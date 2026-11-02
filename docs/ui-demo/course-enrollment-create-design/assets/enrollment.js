/* Static design interactions for Course enrollment · Tạo ghi danh.
   In-memory demo data only: students 1–3 and products 1–4 mirror the dev tenant on 2026-11-02, the rest are
   invented to show every state. Eligibility below imitates the server rules for preview only —
   when adopting, keep the existing search / preflight / commit endpoints as the source of truth. */
(() => {
  const root = document.getElementById('ui-catalog-soft');
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icons = () => { if (window.lucide?.createIcons) window.lucide.createIcons(); };
  const norm = s => String(s).toLocaleLowerCase('vi').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
  const pad = n => String(n).padStart(2, '0');
  const fmt = d => d ? `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}` : '—';
  const fmtDay = d => `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
  const addDays = (d, n) => new Date(d.getTime() + n * 864e5);
  const initials = name => name.split(/\s+/).slice(-2).map(w => w[0]).join('').toUpperCase();

  const students = [
    {id:6, name:'Student 1', email:'student1@gmail.com'},
    {id:7, name:'Student 2', email:'student2@gmail.com'},
    {id:8, name:'Student 3', email:'student3@gmail.com'},
    {id:9, name:'Nguyễn Minh Anh', email:'minhanh.nguyen@example.com'},
    {id:10, name:'Trần Thu Hà', email:'thuha.tran@example.com'},
    {id:11, name:'Lê Quốc Bảo', email:'quocbao.le@example.com'},
    {id:12, name:'Phạm Gia Hân', email:'giahan.pham@example.com'}
  ];
  const D = s => new Date(s);
  const products = [
    {id:1, title:'Demo chấm năng lực – Lớp live online', code:'PRD-20261011-001', ver:4, verCode:'VER-20260928-002', win:null, access:null, review:null},
    {id:2, title:'Sản phẩm live stream 1', code:'PRD-20260806-001', ver:2, verCode:'VER-20260806-002', win:[D('2026-08-01T21:53'), D('2026-08-31T21:54')], access:null, review:null},
    {id:3, title:'Sản phẩm sơ cấp 1', code:'PRD-20260727-001', ver:3, verCode:'VER-20260804-001', win:[D('2026-07-01T21:20'), D('2026-08-15T21:20')], access:null, review:null},
    {id:4, title:'Sản phẩm trung cấp 1', code:'PRD-20260727-002', ver:1, verCode:'VER-20260726-003', win:[D('2026-07-01T21:23'), D('2026-08-28T21:23')], access:null, review:null},
    {id:5, title:'Tiếng Hàn sơ cấp 1 – Lớp tối', code:'PRD-20261020-001', ver:1, verCode:'VER-20261018-001', win:[D('2026-10-15T00:00'), D('2026-11-30T23:59')], access:90, review:30},
    {id:6, title:'TOPIK II cấp tốc', code:'PRD-20261025-002', ver:2, verCode:'VER-20261024-002', win:[D('2026-11-10T00:00'), D('2026-12-31T23:59')], access:60, review:0}
  ];
  // Existing enrollments: an active one blocks the pair; an ended one allows a confirmed re-enrollment.
  const enrollments = [{s:6, p:1, id:27, active:true}, {s:7, p:1, id:24, active:false}, {s:9, p:5, id:21, active:false}];

  let step = 1, selS = new Map(), selP = new Map(), sQuery = '', pQuery = '', checking = false, showIneligible = false;
  let pairs = [], confirmed = new Set(), page = 1, nextId = 28, checkTimer = null;
  const PER_PAGE = 10, LIMIT = 100;
  const dateValue = () => $('enr-date').value ? new Date($('enr-date').value) : null;

  function toast(msg){ $('enr-toast-text').textContent = msg; $('enr-toast').hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => $('enr-toast').hidden = true, 3200); }

  function windowState(p, date){
    if (!p.win) return {status:'unlimited', label:'Không giới hạn thời gian đăng ký', note:'Áp dụng với mọi ngày ghi danh', cls:'ok'};
    const range = `${fmt(p.win[0])} → ${fmt(p.win[1])}`;
    if (!date) return {status:'unknown', label:range, note:'', cls:''};
    // One sentence that already compares the window with the chosen date — no separate reason line needed.
    if (date < p.win[0]) return {status:'not_open', label:range, note:`Chưa mở tại ngày ghi danh ${fmt(date)}`, cls:'wait', reason:`chưa mở đăng ký tại ngày ghi danh ${fmt(date)} (mở lúc ${fmt(p.win[0])})`};
    if (date > p.win[1]) return {status:'ended', label:range, note:`Đã đóng trước ngày ghi danh ${fmt(date)}`, cls:'bad', reason:`đã đóng đăng ký lúc ${fmt(p.win[1])}, trước ngày ghi danh ${fmt(date)}`};
    return {status:'open', label:range, note:'Ngày ghi danh hợp lệ', cls:'ok'};
  }
  function eligibility(p){
    if (!selS.size) return {state:'unchecked'};
    const w = windowState(p, dateValue());
    if (w.reason) return {state:'ineligible', kind:'window', reason:w.reason};
    const blocked = [...selS.values()].filter(s => enrollments.some(e => e.s === s.id && e.p === p.id && e.active));
    if (blocked.length) {
      const id = blocked.length === 1 ? ' (#' + enrollments.find(e => e.s === blocked[0].id && e.p === p.id).id + ')' : '';
      return {state:'ineligible', kind:'enrollment', reason:`${blocked.map(s => s.name).join(', ')} đang có ghi danh hoạt động${id}`};
    }
    return {state:'eligible'};
  }

  function renderStudents(){
    const q = norm(sQuery);
    const list = students.filter(s => !q || norm(s.name + ' ' + s.email).includes(q));
    $('enr-students').innerHTML = list.map(s => `<li><label class="enr-row"><input type="checkbox" data-student="${s.id}" ${selS.has(s.id) ? 'checked' : ''} aria-label="${esc(s.name)}"><span class="enr-avatar" aria-hidden="true">${esc(initials(s.name))}</span><span class="enr-row__body"><span class="enr-row__name">${esc(s.name)}</span><span class="enr-row__sub">${esc(s.email)}</span></span></label></li>`).join('') || '<li class="enr-empty">Không tìm thấy học viên phù hợp.</li>';
    const all = $('enr-students-all');
    all.checked = list.length > 0 && list.every(s => selS.has(s.id));
    all.indeterminate = list.some(s => selS.has(s.id)) && !all.checked;
    $('enr-students-count').textContent = `Đã chọn ${selS.size}`;
    $('enr-students-total').textContent = `${list.length} kết quả`;
    $('enr-students-chips').innerHTML = [...selS.values()].map(s => `<span class="enr-chip">${esc(s.name)}<button type="button" data-unstudent="${s.id}" aria-label="Bỏ chọn ${esc(s.name)}"><i data-lucide="x" class="ui-icon" aria-hidden="true"></i></button></span>`).join('');
  }

  function productRow(p, e){
    const w = windowState(p, dateValue());
    const locked = !selS.size || checking;
    const sel = selP.has(p.id);
    const invalidSel = sel && e.state === 'ineligible';
    const disabled = (locked || e.state !== 'eligible') && !sel;
    const badge = e.state === 'eligible' ? '<span class="enr-badge enr-badge--success"><i data-lucide="check" class="ui-icon" aria-hidden="true"></i>Đủ điều kiện</span>'
      : e.state === 'ineligible' ? '<span class="enr-badge enr-badge--danger">Không đủ điều kiện</span>' : '';
    const dur = p.access ? ` · Truy cập ${p.access} ngày${p.review ? ` · Ôn tập ${p.review} ngày` : ''}` : '';
    // Window problems are explained once, in the window line; enrollment problems get their own line.
    const reasonLine = e.state === 'ineligible' && e.kind === 'enrollment' ? `<span class="enr-reason" id="enr-reason-${p.id}">${esc(e.reason.charAt(0).toUpperCase() + e.reason.slice(1))}.</span>` : '';
    const describedBy = e.state === 'ineligible' ? `aria-describedby="${e.kind === 'enrollment' ? 'enr-reason-' : 'enr-window-'}${p.id}"` : '';
    return `<li class="${invalidSel ? 'is-invalid-selected' : ''}"><div class="enr-row ${disabled ? 'is-disabled' : ''}"><input type="checkbox" id="enr-p-${p.id}" data-product="${p.id}" ${sel ? 'checked' : ''} ${disabled ? 'disabled' : ''} ${describedBy}>`+
      `<span class="enr-row__body"><span class="enr-row__title"><label class="enr-row__name" for="enr-p-${p.id}">${esc(p.title)}</label>${badge}</span>`+
      `<span class="enr-row__sub">${esc(p.code)} · <strong style="font-weight:500;color:#5b6371">Version ${p.ver}</strong> · ${esc(p.verCode)}${dur}</span>`+
      `<span class="enr-row__window" id="enr-window-${p.id}"><i data-lucide="calendar-range" class="ui-icon" aria-hidden="true"></i><span>Đăng ký: ${esc(w.label)}</span>${w.note ? `<span class="enr-window--${w.cls}">· ${esc(w.note)}</span>` : ''}</span>`+
      reasonLine +
      (invalidSel ? `<span class="enr-row__actions"><button type="button" class="ui-button ui-button--sm" data-unproduct="${p.id}"><i data-lucide="x" class="ui-icon" aria-hidden="true"></i>Bỏ chọn</button></span>` : '')+
      `</span></div></li>`;
  }

  function renderProducts(){
    const q = norm(pQuery);
    const list = products.filter(p => !q || norm(p.title + ' ' + p.code).includes(q)).map(p => ({p, e: eligibility(p)}));
    const ok = list.filter(x => x.e.state === 'eligible');
    const invalidSel = list.filter(x => x.e.state === 'ineligible' && selP.has(x.p.id));
    const bad = list.filter(x => x.e.state === 'ineligible' && !selP.has(x.p.id));
    const other = list.filter(x => x.e.state === 'unchecked');
    let html = '';
    if (!selS.size) {
      html += other.map(x => productRow(x.p, x.e)).join('');
    } else {
      if (invalidSel.length && !checking) {
        html += `<li class="enr-group-label enr-group-label--danger">Đã chọn nhưng không còn đủ điều kiện (${invalidSel.length})</li>` + invalidSel.map(x => productRow(x.p, x.e)).join('');
        if (ok.length) html += `<li class="enr-group-label">Đủ điều kiện (${ok.length})</li>`;
      }
      if (!ok.length && !checking) html += `<li class="enr-empty"><strong style="display:block;color:#30343d;font-weight:500">Không có sản phẩm đủ điều kiện</strong>Thử đổi ngày ghi danh hoặc xem lý do ở nhóm không đủ điều kiện bên dưới.</li>`;
      html += ok.map(x => productRow(x.p, x.e)).join('');
      if (bad.length) {
        html += `<li><button type="button" class="enr-group-toggle" id="enr-ineligible-toggle" aria-expanded="${showIneligible}"><span>${showIneligible ? 'Ẩn' : 'Xem'} ${bad.length} sản phẩm không đủ điều kiện${invalidSel.length ? ' khác' : ''}</span><i data-lucide="chevron-down" class="ui-icon" aria-hidden="true"></i></button></li>`;
        if (showIneligible) html += bad.map(x => productRow(x.p, x.e)).join('');
      }
    }
    if (!list.length) html = '<li class="enr-empty">Không tìm thấy sản phẩm phù hợp.</li>';
    $('enr-products').innerHTML = html;
    $('enr-checking').hidden = !checking;
    $('enr-locked-note').hidden = selS.size > 0; // stays above the scroll area so the reason is always visible
    const okIds = ok.map(x => x.p.id);
    const all = $('enr-products-all');
    all.disabled = !selS.size || checking || !ok.length;
    all.checked = ok.length > 0 && okIds.every(id => selP.has(id));
    const invalid = [...selP.values()].map(p => ({p, e: eligibility(p)})).filter(x => selS.size && !checking && x.e.state === 'ineligible');
    const okSel = [...selP.keys()].filter(id => okIds.includes(id)).length;
    $('enr-products-count').innerHTML = !selS.size ? 'Chưa kiểm tra'
      : `Đã chọn ${okSel}/${ok.length} đủ điều kiện${invalid.length ? ` · <span style="color:var(--enr-danger)">${invalid.length} cần xử lý</span>` : ''}`;
    $('enr-products-summary').textContent = selS.size && !checking ? `${ok.length} đủ điều kiện · ${bad.length + invalidSel.length} không đủ` : `${list.length} sản phẩm`;
    $('enr-product-panel').classList.toggle('is-locked', !selS.size);
    // Alert: what is wrong, why (with the chosen date), and both ways to fix it.
    const one = invalid.length === 1, windowIssue = invalid.some(x => x.e.kind === 'window');
    $('enr-invalid-alert').hidden = !invalid.length;
    $('enr-invalid-title').textContent = one ? `“${invalid[0]?.p.title}” không còn đủ điều kiện` : `${invalid.length} sản phẩm đã chọn không còn đủ điều kiện`;
    $('enr-invalid-text').textContent = one ? `Sản phẩm ${invalid[0]?.e.reason}.` : 'Bỏ chọn các sản phẩm bên dưới hoặc đổi ngày ghi danh để tiếp tục.';
    $('enr-invalid-list').hidden = one;
    $('enr-invalid-list').innerHTML = one ? '' : invalid.map(x => `<li><strong style="display:inline;font-weight:500">${esc(x.p.title)}</strong> — ${esc(x.e.reason)}</li>`).join('');
    $('enr-fix-date').hidden = !windowIssue;
    $('enr-remove-invalid').textContent = one ? 'Bỏ chọn sản phẩm này' : `Bỏ chọn ${invalid.length} sản phẩm`;
    return invalid.length;
  }

  function renderBar(){
    const n = selS.size * selP.size;
    $('enr-f-students').textContent = selS.size; $('enr-f-products').textContent = selP.size; $('enr-f-pairs').textContent = n;
    $('enr-meter').style.width = Math.min(100, n / LIMIT * 100) + '%';
    $('enr-limit').classList.toggle('is-near', n >= 80 && n <= LIMIT);
    $('enr-limit').classList.toggle('is-over', n > LIMIT);
    $('enr-limit-text').textContent = `${n}/${LIMIT} cặp tối đa`;
  }

  function render(){
    root.querySelectorAll('[data-step-item]').forEach(li => {
      const k = +li.dataset.stepItem;
      li.classList.toggle('is-done', k < step);
      if (k === step) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
      li.querySelector('.enr-step__dot').innerHTML = k < step ? '<i data-lucide="check" class="ui-icon" aria-hidden="true"></i>' : k;
    });
    [1, 2, 3].forEach(k => $('enr-step-' + k).hidden = step !== k);
    $('enr-actionbar').hidden = step === 3;
    $('enr-back').hidden = step !== 2; $('enr-next').hidden = step !== 1; $('enr-submit').hidden = step !== 2;
    renderBar();
    $('enr-limit').hidden = step !== 1;
    const msg = $('enr-action-msg'); msg.classList.remove('is-error');
    if (step === 1) {
      renderStudents();
      const invalid = renderProducts();
      const n = selS.size * selP.size;
      const ready = n >= 1 && n <= LIMIT && !invalid && !checking && dateValue();
      $('enr-next').disabled = !ready;
      msg.textContent = !dateValue() ? 'Nhập ngày ghi danh.' : checking ? 'Đang kiểm tra điều kiện…' : !selS.size ? 'Chọn học viên để bắt đầu.' : !selP.size ? 'Chọn ít nhất một sản phẩm đủ điều kiện.' : invalid ? 'Bỏ các sản phẩm không đủ điều kiện để tiếp tục.' : n > LIMIT ? 'Vượt quá 100 cặp trong một lần tạo.' : '';
      if (invalid || n > LIMIT || !dateValue()) msg.classList.add('is-error');
    } else if (step === 2) renderReview();
    icons();
  }

  // Step 2 — pairs grouped by product: the product, version and time windows are shown once per group.
  let pairFilter = 'all';
  function buildPairs(){
    pairs = [];
    selP.forEach(p => selS.forEach(s => {
      const prev = enrollments.find(e => e.s === s.id && e.p === p.id && !e.active);
      pairs.push({s, p, re: prev ? prev.id : null, key: `${s.id}:${p.id}`});
    }));
    confirmed = new Set(); page = 1; pairFilter = 'all';
  }
  function timeInline(p){
    const d = dateValue();
    if (!p.access) return '<span>Không thiết lập thời hạn truy cập</span>';
    const end = addDays(d, p.access);
    return `<span>Truy cập <b>${p.access} ngày</b> · ${fmtDay(d)} → ${fmtDay(end)}</span>` +
      (p.review ? `<span>Ôn tập <b>${p.review} ngày</b> · đến ${fmtDay(addDays(end, p.review))}</span>` : '<span>Không có ôn tập</span>');
  }
  function productHeader(p, count, cols){
    return `<tr class="enr-prod-row"><td colspan="${cols}"><div class="enr-prod"><span class="enr-prod__name">${esc(p.title)}</span>`+
      `<span class="enr-prod__code">${esc(p.code)} · Version ${p.ver} · ${esc(p.verCode)}</span>`+
      `<span class="enr-prod__time"><i data-lucide="clock" class="ui-icon" aria-hidden="true"></i>${timeInline(p)}</span>`+
      `<span class="enr-prod__count">${count} học viên</span></div></td></tr>`;
  }
  function groupedRows(list, offset, cols, cell){
    let html = '', last = null;
    list.forEach((x, i) => {
      if (x.p !== last) { html += productHeader(x.p, pairs.filter(y => y.p === x.p).length, cols); last = x.p; }
      html += cell(x, offset + i + 1);
    });
    return html;
  }
  function renderReview(){
    const re = pairs.filter(x => x.re);
    const pending = re.filter(x => !confirmed.has(x.key));
    $('enr-stat-date').textContent = fmt(dateValue());
    $('enr-pairs-summary').innerHTML = `${pairs.length - re.length} ghi danh mới` + (re.length ? ` · <span style="color:var(--enr-warn)">${re.length} ghi danh lại${pending.length ? `, ${pending.length} chưa xác nhận` : ' đã xác nhận'}</span>` : '');
    $('enr-confirm-all').hidden = re.length < 2 || !pending.length;
    $('enr-pair-tabs').hidden = !re.length;
    if (!re.length) pairFilter = 'all';
    $('enr-count-all').textContent = pairs.length; $('enr-count-pending').textContent = pending.length;
    root.querySelectorAll('[data-pairfilter]').forEach(b => b.setAttribute('aria-selected', String(b.dataset.pairfilter === pairFilter)));
    const list = pairFilter === 'pending' ? pending : pairs;
    const last = Math.max(1, Math.ceil(list.length / PER_PAGE)); page = Math.min(page, last);
    const slice = list.slice((page - 1) * PER_PAGE, page * PER_PAGE);
    $('enr-pairs').innerHTML = !list.length ? '<tr><td colspan="3" class="enr-empty">Tất cả cặp ghi danh lại đã được xác nhận.</td></tr>'
      : groupedRows(slice, (page - 1) * PER_PAGE, 3, (x, n) => {
        const result = x.re
          ? `<div class="enr-reenroll"><span class="enr-badge enr-badge--warn" style="align-self:flex-start"><i data-lucide="rotate-ccw" class="ui-icon" aria-hidden="true"></i>Ghi danh lại</span><label class="ui-check"><input type="checkbox" data-confirm="${x.key}" ${confirmed.has(x.key) ? 'checked' : ''}>Xác nhận ghi danh lại</label><p>Tạo lượt học mới; dữ liệu của ghi danh <a class="enr-link" href="#">#${x.re}</a> được giữ nguyên.</p></div>`
          : '<span class="enr-new"><i data-lucide="plus" class="ui-icon" aria-hidden="true"></i>Ghi danh mới</span>';
        return `<tr class="${x.re && !confirmed.has(x.key) ? 'is-attention' : ''}"><td class="enr-col-no">${n}</td><td data-label="Học viên"><span class="enr-cell-title">${esc(x.s.name)}</span><span class="enr-cell-sub">${esc(x.s.email)}</span></td><td data-label="Kết quả dự kiến" class="enr-col-result">${result}</td></tr>`;
      });
    $('enr-pairs-pager').innerHTML = list.length > PER_PAGE
      ? `<span>${(page - 1) * PER_PAGE + 1}–${Math.min(page * PER_PAGE, list.length)} / ${list.length} ghi danh</span><nav class="mk-pages" aria-label="Phân trang danh sách ghi danh"><button type="button" class="mk-page" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''} aria-label="Trang trước">‹</button><span class="mk-page" aria-current="page">${page}</span><button type="button" class="mk-page" data-page="${page + 1}" ${page >= last ? 'disabled' : ''} aria-label="Trang sau">›</button></nav>`
      : '';
    $('enr-submit').disabled = pending.length > 0;
    $('enr-submit').querySelector('span').textContent = `Tạo ${pairs.length} ghi danh`;
    const msg = $('enr-action-msg');
    msg.textContent = pending.length ? `Còn ${pending.length} cặp cần xác nhận ghi danh lại.` : `Ngày ghi danh ${fmt(dateValue())} · sẵn sàng tạo.`;
    msg.classList.toggle('is-error', pending.length > 0);
  }

  function renderDone(){
    const now = new Date();
    $('enr-done-text').textContent = `Hệ thống đã tạo thành công ${pairs.length} ghi danh độc lập.`;
    $('enr-done-at').textContent = fmt(now); $('enr-done-date').textContent = fmt(dateValue());
    const re = pairs.filter(x => x.re).length;
    $('enr-done-summary').textContent = `${pairs.length - re} ghi danh mới · ${re} ghi danh lại`;
    $('enr-done-rows').innerHTML = groupedRows(pairs, 0, 4, (x, n) => `<tr><td class="enr-col-no">${n}</td><td data-label="Học viên"><span class="enr-cell-title">${esc(x.s.name)}</span><span class="enr-cell-sub">${esc(x.s.email)}</span></td><td data-label="Kết quả">${x.re ? `<span class="enr-badge enr-badge--warn"><i data-lucide="rotate-ccw" class="ui-icon" aria-hidden="true"></i>Ghi danh lại từ #${x.re}</span>` : '<span class="enr-new"><i data-lucide="plus" class="ui-icon" aria-hidden="true"></i>Ghi danh mới</span>'}</td><td data-label="Mã ghi danh" class="enr-col-id"><a class="enr-link" href="#" style="font-weight:500">#${x.id}</a></td></tr>`);
    const notes = $('enr-notes').value.trim();
    $('enr-done-notes').hidden = !notes;
    $('enr-done-notes').textContent = notes ? 'Ghi chú nội bộ: ' + notes : '';
  }

  function recheck(){
    checking = selS.size > 0; render();
    clearTimeout(checkTimer);
    if (checking) checkTimer = setTimeout(() => { checking = false; render(); }, 600);
  }
  function go(n){ step = n; if (n === 1) checking = false; render(); window.scrollTo({top: 0, behavior: 'smooth'}); }
  function setNow(){ const d = new Date(); $('enr-date').value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`; }
  function reset(){ selS.clear(); selP.clear(); pairs = []; confirmed.clear(); showIneligible = false; $('enr-notes').value = ''; $('enr-notes-field').hidden = true; $('enr-notes-toggle').hidden = false; setNow(); go(1); }

  // Events
  root.addEventListener('change', e => {
    const t = e.target;
    if (t.dataset.student) { const s = students.find(x => x.id === +t.dataset.student); t.checked ? selS.set(s.id, s) : selS.delete(s.id); recheck(); }
    else if (t.dataset.product) { const p = products.find(x => x.id === +t.dataset.product); t.checked ? selP.set(p.id, p) : selP.delete(p.id); render(); }
    else if (t.id === 'enr-students-all') { const q = norm(sQuery); students.filter(s => !q || norm(s.name + ' ' + s.email).includes(q)).forEach(s => t.checked ? selS.set(s.id, s) : selS.delete(s.id)); recheck(); }
    else if (t.id === 'enr-products-all') { products.filter(p => eligibility(p).state === 'eligible').forEach(p => t.checked ? selP.set(p.id, p) : selP.delete(p.id)); render(); }
    else if (t.id === 'enr-date') recheck();
    else if (t.dataset.confirm) { t.checked ? confirmed.add(t.dataset.confirm) : confirmed.delete(t.dataset.confirm); render(); }
  });
  root.addEventListener('click', e => {
    const t = e.target.closest('button,a');
    if (!t) return;
    if (t.dataset.unstudent) { selS.delete(+t.dataset.unstudent); recheck(); }
    else if (t.dataset.unproduct) { selP.delete(+t.dataset.unproduct); render(); }
    else if (t.id === 'enr-ineligible-toggle') { showIneligible = !showIneligible; render(); }
    else if (t.id === 'enr-fix-date') { $('enr-date').scrollIntoView({block:'center', behavior:'smooth'}); $('enr-date').focus(); }
    else if (t.id === 'enr-remove-invalid') { [...selP.values()].forEach(p => { if (eligibility(p).state === 'ineligible') selP.delete(p.id); }); render(); toast('Đã bỏ chọn sản phẩm không đủ điều kiện.'); }
    else if (t.id === 'enr-next') { buildPairs(); go(2); }
    else if (t.id === 'enr-submit') { pairs.forEach(x => x.id = nextId++); renderDone(); go(3); }
    else if (t.dataset.go) go(+t.dataset.go);
    else if (t.dataset.page) { page = +t.dataset.page; render(); }
    else if (t.dataset.pairfilter) { pairFilter = t.dataset.pairfilter; page = 1; render(); }
    else if (t.id === 'enr-notes-toggle') { const f = $('enr-notes-field'); f.hidden = false; t.hidden = true; $('enr-notes').focus(); }
    else if (t.id === 'enr-confirm-all') { $('enr-confirm-count').textContent = pairs.filter(x => x.re && !confirmed.has(x.key)).length; $('enr-confirm-dialog').showModal(); }
    else if (t.hasAttribute('data-close')) t.closest('dialog').close();
    else if (t.dataset.demo === 'reset') reset();
    else if (t.dataset.demo === 'sample') { reset(); [7, 9, 10].forEach(id => selS.set(id, students.find(s => s.id === id))); [1, 5].forEach(id => selP.set(id, products.find(p => p.id === id))); render(); toast('Đã chọn 3 học viên × 2 sản phẩm (có 2 cặp ghi danh lại).'); }
    else if (t.dataset.demo === 'invalid') { reset(); selS.set(8, students.find(s => s.id === 8)); selP.set(5, products.find(p => p.id === 5)); $('enr-date').value = '2026-12-05T09:00'; render(); toast('Ngày ghi danh 05/12/2026 nằm ngoài khoảng đăng ký của sản phẩm đã chọn.'); }
  });
  $('enr-confirm-form').addEventListener('submit', e => { e.preventDefault(); pairs.filter(x => x.re).forEach(x => confirmed.add(x.key)); $('enr-confirm-dialog').close(); render(); });
  $('enr-confirm-dialog').addEventListener('click', e => { if (e.target === e.currentTarget) e.currentTarget.close(); });
  $('enr-student-search').addEventListener('input', e => { sQuery = e.target.value.trim(); render(); });
  $('enr-product-search').addEventListener('input', e => { pQuery = e.target.value.trim(); render(); });

  // Minimal shell (same behaviour as admin-layout-developer).
  const shell = $('admin-shell'), sidebar = $('admin-sidebar'), drawer = $('admin-drawer'), toggle = $('admin-menu-toggle'), closeBtn = $('admin-close');
  const mq = matchMedia('(max-width:680px)'); let collapsed = false;
  const sync = () => { toggle.setAttribute('aria-label', mq.matches ? 'Mở menu' : collapsed ? 'Mở rộng menu' : 'Thu gọn menu'); toggle.setAttribute('aria-expanded', String(mq.matches ? drawer.open : !collapsed)); shell.classList.toggle('is-collapsed', collapsed && !mq.matches); };
  toggle.addEventListener('click', () => { if (mq.matches) { drawer.append(sidebar); closeBtn.hidden = false; drawer.showModal(); } else collapsed = !collapsed; sync(); });
  closeBtn.addEventListener('click', () => drawer.close());
  drawer.addEventListener('close', () => { shell.prepend(sidebar); closeBtn.hidden = true; sync(); toggle.focus(); });
  drawer.addEventListener('click', e => { if (e.target === drawer) drawer.close(); });
  mq.addEventListener('change', () => { if (drawer.open) drawer.close(); sync(); });
  sync();

  setNow(); render();
})();
