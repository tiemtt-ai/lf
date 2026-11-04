/* Static design interactions for Course cohort · Chi tiết lịch học.
   Demo "month" mirrors the dev schedule shown on 2026-11-04 (lịch chính tháng 11); the other demos are invented.
   Sessions are computed here only for preview — when adopting, keep using the server preview ($preview). */
(() => {
  const $ = id => document.getElementById(id);
  const root = $('ui-catalog-soft');
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icons = () => { if (window.lucide?.createIcons) window.lucide.createIcons(); };
  const pad = n => String(n).padStart(2, '0');
  const iso = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const dm = d => `${pad(d.getDate())}/${pad(d.getMonth() + 1)}`;
  const dmy = d => `${dm(d)}/${d.getFullYear()}`;
  const day = s => new Date(s + 'T00:00:00');
  const WD = ['Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ Nhật'];
  const WD_SHORT = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
  const wdOf = d => (d.getDay() + 6) % 7; // 0 = Monday
  const mins = t => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
  const hrs = m => { const h = Math.floor(m / 60), r = m % 60; return r ? `${h} giờ ${r} phút` : `${h} giờ`; };
  const TODAY = day('2026-11-04');

  const demos = {
    month: {name:'lịch chính tháng 11', status:'current', from:'2026-11-01', to:'2026-11-30', tz:'Asia/Ho_Chi_Minh', tzLabel:'GMT+7',
      slots:[{wd:0, s:'11:00', e:'13:00'}, {wd:1, s:'11:00', e:'12:00'}, {wd:4, s:'12:00', e:'13:00'}],
      excl:[{d:'2026-11-24', r:'ngày lễ'}]},
    quarter: {name:'Lịch học buổi tối Q4', status:'current', from:'2026-10-15', to:'2027-01-15', tz:'Asia/Ho_Chi_Minh', tzLabel:'GMT+7',
      slots:[{wd:1, s:'19:00', e:'20:30'}, {wd:3, s:'19:00', e:'20:30'}, {wd:5, s:'08:30', e:'10:30'}],
      excl:[{d:'2026-11-20', r:'Ngày Nhà giáo'}, {d:'2026-12-24', r:'Giáng sinh'}, {d:'2026-12-31', r:''}, {d:'2027-01-01', r:'Tết Dương lịch'}]},
    upcoming: {name:'Lịch ôn thi TOPIK', status:'upcoming', from:'2026-12-01', to:'2026-12-20', tz:'Asia/Seoul', tzLabel:'GMT+9',
      slots:[{wd:5, s:'09:00', e:'11:00'}, {wd:6, s:'09:00', e:'11:00'}], excl:[]}
  };
  const STATUS = {current:['sch-badge--success', 'Đang áp dụng'], upcoming:['', 'Sắp áp dụng'], ended:['sch-badge--muted', 'Đã kết thúc']};

  let cur = demos.month, curKey = 'month', view = 'calendar', month = null, plan = [];

  function build(){
    const from = day(cur.from), to = day(cur.to), ex = new Map(cur.excl.map(x => [x.d, x.r]));
    plan = [];
    for (let d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
      cur.slots.filter(s => s.wd === wdOf(d)).sort((a, b) => mins(a.s) - mins(b.s)).forEach(s => {
        plan.push({date:new Date(d), s:s.s, e:s.e, min:mins(s.e) - mins(s.s), excluded:ex.has(iso(d)), reason:ex.get(iso(d))});
      });
    }
  }
  const sessions = () => plan.filter(p => !p.excluded);
  const nextSession = () => cur.status === 'current' ? sessions().find(p => p.date >= TODAY) : null;

  function renderHead(){
    const from = day(cur.from), to = day(cur.to), days = Math.round((to - from) / 864e5) + 1;
    $('sch-name').textContent = cur.name;
    const [cls, label] = STATUS[cur.status];
    $('sch-status').innerHTML = `<span class="sch-badge ${cls}">${label}</span>`;
    $('sch-period').innerHTML = `${dmy(from)} → ${dmy(to)}<small>${days} ngày</small>`;
    const ss = sessions(), done = ss.filter(p => p.date < TODAY).length;
    const nx = nextSession();
    $('sch-count').innerHTML = `${ss.length} buổi` + (cur.status === 'current' && done ? `<small>${done} đã qua · ${ss.length - done} còn lại</small>` : '') + (nx ? `<small style="display:block;margin:2px 0 0">Tiếp theo: ${WD[wdOf(nx.date)]}, ${dm(nx.date)} · ${nx.s}</small>` : '');
    $('sch-hours').innerHTML = `${hrs(ss.reduce((t, p) => t + p.min, 0))}<small>≈ ${hrs(cur.slots.reduce((t, s) => t + mins(s.e) - mins(s.s), 0))}/tuần</small>`;
    $('sch-tz').innerHTML = `${esc(cur.tz)}<small>${esc(cur.tzLabel)}</small>`;
    $('sch-week-count').textContent = `· ${cur.slots.length} khung giờ`;
    $('sch-week').innerHTML = WD.map((n, i) => {
      const slots = cur.slots.filter(s => s.wd === i);
      return `<li class="${slots.length ? 'is-on' : ''}"><span class="sch-week__day">${n}</span>${slots.length ? slots.map(s => `<span class="sch-week__time">${s.s}–${s.e}<small>${hrs(mins(s.e) - mins(s.s)).replace(' giờ', 'g').replace(' phút', 'p')}</small></span>`).join('') : '<span class="sch-week__time">—</span>'}</li>`;
    }).join('');
    $('sch-excl-count').textContent = `· ${cur.excl.length}`;
    $('sch-excl').innerHTML = cur.excl.length ? cur.excl.map(x => {
      const d = day(x.d), hit = plan.filter(p => p.excluded && iso(p.date) === x.d).length;
      return `<li><strong style="font-weight:500">${WD_SHORT[wdOf(d)]}, ${dmy(d)}</strong>${x.r ? `<span>${esc(x.r)}</span>` : ''}<small>${hit ? `bỏ ${hit} buổi` : 'không trùng buổi học'}</small></li>`;
    }).join('') : '<li class="sch-empty" style="border:0;background:none;padding:0">Không có ngày loại trừ.</li>';
  }

  function renderCalendar(){
    const first = new Date(month.getFullYear(), month.getMonth(), 1), last = new Date(month.getFullYear(), month.getMonth() + 1, 0);
    const from = day(cur.from), to = day(cur.to);
    $('sch-month').textContent = `Tháng ${month.getMonth() + 1}/${month.getFullYear()}`;
    $('sch-prev').disabled = first <= new Date(from.getFullYear(), from.getMonth(), 1);
    $('sch-next').disabled = last >= new Date(to.getFullYear(), to.getMonth() + 1, 0);
    const inMonth = plan.filter(p => p.date.getMonth() === month.getMonth() && p.date.getFullYear() === month.getFullYear());
    $('sch-month-count').textContent = `${inMonth.filter(p => !p.excluded).length} buổi trong tháng`;
    let html = WD_SHORT.map(d => `<div class="sch-cal__dow" role="columnheader">${d}</div>`).join('');
    for (let i = 0; i < wdOf(first); i++) html += '<div class="sch-cal__day is-blank" aria-hidden="true"><span class="sch-cal__num">.</span></div>';
    for (let d = new Date(first); d <= last; d.setDate(d.getDate() + 1)) {
      const out = d < from || d > to, items = inMonth.filter(p => iso(p.date) === iso(d));
      const label = `${WD[wdOf(d)]}, ${dmy(d)}` + (out ? ', ngoài thời gian áp dụng' : items.length ? ': ' + items.map(p => p.excluded ? `loại trừ ${p.s}–${p.e}` : `buổi ${p.s}–${p.e}`).join(', ') : '');
      html += `<div class="sch-cal__day ${out ? 'is-out' : ''} ${iso(d) === iso(TODAY) ? 'is-today' : ''}" role="gridcell" aria-label="${esc(label)}"><span class="sch-cal__num">${d.getDate()}</span>` +
        items.map(p => p.excluded ? `<span class="sch-excluded" title="${esc(p.reason || 'Ngày loại trừ')}"><s>${p.s}–${p.e}</s> ${esc(p.reason || 'Nghỉ')}</span>` : `<span class="sch-session ${p.date < TODAY ? 'is-past' : ''} ${p === nextSession() ? 'is-next' : ''}"${p === nextSession() ? ' title="Buổi tiếp theo"' : ''}>${p.s}–${p.e}</span>`).join('') + '</div>';
    }
    const tail = (7 - (wdOf(first) + last.getDate()) % 7) % 7;
    for (let i = 0; i < tail; i++) html += '<div class="sch-cal__day is-blank" aria-hidden="true"><span class="sch-cal__num">.</span></div>';
    $('sch-cal').innerHTML = html;
  }

  function renderList(){
    let html = '', lastWeek = null, n = 0;
    plan.forEach(p => {
      const mon = new Date(p.date); mon.setDate(mon.getDate() - wdOf(mon));
      const key = iso(mon);
      if (key !== lastWeek) {
        const sun = new Date(mon); sun.setDate(sun.getDate() + 6);
        const wk = plan.filter(x => { const m = new Date(x.date); m.setDate(m.getDate() - wdOf(m)); return iso(m) === key && !x.excluded; }).length;
        html += `<li class="sch-list__week"><span>Tuần ${dm(mon)} – ${dmy(sun)}</span><span>${wk} buổi</span></li>`;
        lastWeek = key;
      }
      if (!p.excluded) n++;
      html += `<li class="sch-list__row ${p === nextSession() ? 'is-next' : ''} ${p.excluded ? 'is-excluded' : ''} ${!p.excluded && p.date < TODAY ? 'is-past' : ''}"><span class="sch-list__no">${p.excluded ? '' : n}</span><span class="sch-list__date">${WD[wdOf(p.date)]}, ${dmy(p.date)}</span><span class="sch-list__time">${p.s}–${p.e} <span class="sch-list__dur">· ${hrs(p.min)}</span></span>` +
        (p.excluded ? `<span class="sch-badge sch-badge--warn">Loại trừ${p.reason ? ' · ' + esc(p.reason) : ''}</span>` : p === nextSession() ? '<span class="sch-badge sch-badge--success">Buổi tiếp theo</span>' : p.date < TODAY ? '<span class="sch-list__dur">Đã qua</span>' : '<span></span>') + '</li>';
    });
    $('sch-list').innerHTML = html || '<li class="sch-empty" style="padding:14px">Không có buổi dự kiến trong thời gian áp dụng.</li>';
    const next = $('sch-list').querySelector('.is-next');
    if (next) requestAnimationFrame(() => { const body = next.closest('.ui-modal__body'), list = $('sch-list'); body.scrollTop = list.offsetTop - 60; list.scrollTop = next.offsetTop - list.offsetTop - 40; });
  }

  function render(){
    renderHead();
    $('sch-view-calendar').hidden = view !== 'calendar'; $('sch-view-list').hidden = view !== 'list';
    root.querySelectorAll('[data-view]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.view === view)));
    if (view === 'calendar') renderCalendar(); else renderList();
    icons();
  }
  function load(key){
    cur = demos[key]; curKey = key; build();
    const from = day(cur.from), firstSession = sessions().find(p => p.date >= TODAY) || sessions()[0];
    const start = cur.status === 'current' && firstSession ? firstSession.date : from;
    month = new Date(start.getFullYear(), start.getMonth(), 1);
    root.querySelectorAll('[data-demo]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.demo === key)));
    render();
  }

  root.addEventListener('click', e => {
    const t = e.target.closest('button,a');
    if (!t) return;
    if (t.dataset.demo) { load(t.dataset.demo); if (!$('sch-dialog').open) $('sch-dialog').showModal(); }
    else if (t.hasAttribute('data-open-detail')) $('sch-dialog').showModal();
    else if (t.hasAttribute('data-close')) $('sch-dialog').close();
    else if (t.dataset.view) { view = t.dataset.view; render(); }
    else if (t.id === 'sch-prev') { month = new Date(month.getFullYear(), month.getMonth() - 1, 1); render(); }
    else if (t.id === 'sch-next') { month = new Date(month.getFullYear(), month.getMonth() + 1, 1); render(); }
  });
  $('sch-dialog').addEventListener('click', e => { if (e.target === e.currentTarget) e.currentTarget.close(); });
  if (matchMedia('(max-width:680px)').matches) view = 'list';

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


  // ===== Form: Thêm / Sửa lịch học (design only — the server preview and validation stay the source of truth) =====
  const COHORT = {month:{from:'2026-11-01', to:'2026-11-30'}, quarter:{from:'2026-10-01', to:'2027-01-31'}, upcoming:{from:'2026-11-01', to:'2026-12-31'}};
  const MAX_SLOTS = 50, MAX_EXCL = 366;
  let f = null, fOrig = '', fMonth = null, fMode = 'edit', fTried = false, origCount = 0;
  const clone = o => JSON.parse(JSON.stringify(o));
  const snap = () => JSON.stringify({n:f.name, tz:f.tz, from:f.from, to:f.to, slots:f.slots, excl:f.excl});
  const cohort = () => COHORT[curKey];

  function planOf(sch){
    const out = [];
    if (!sch.from || !sch.to || sch.from > sch.to) return out;
    const ex = new Map(sch.excl.map(x => [x.d, x.r]));
    for (let d = day(sch.from); d <= day(sch.to); d.setDate(d.getDate() + 1)) {
      sch.slots.filter(s => s.wd === wdOf(d) && s.s && s.e && mins(s.e) > mins(s.s)).sort((a, b) => mins(a.s) - mins(b.s))
        .forEach(s => out.push({date:new Date(d), s:s.s, e:s.e, min:mins(s.e) - mins(s.s), excluded:ex.has(iso(d)), reason:ex.get(iso(d))}));
    }
    return out;
  }

  function validate(){
    const e = {fields:{}, slots:{}, list:[]};
    if (!f.name.trim()) e.fields.name = 'Vui lòng nhập tên lịch.';
    const c = cohort();
    if (!f.from || !f.to) e.fields.period = 'Vui lòng chọn ngày bắt đầu và ngày kết thúc áp dụng.';
    else if (f.from > f.to) e.fields.period = 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.';
    else if (f.from < c.from || f.to > c.to) e.fields.period = `Khoảng áp dụng phải nằm trong thời gian vận hành của lớp, từ ${dmy(day(c.from))} đến ${dmy(day(c.to))}.`;
    if (!f.slots.length) e.fields.slots = 'Chọn ít nhất một ngày học trong tuần.';
    f.slots.forEach((s, i) => {
      if (!s.s || !s.e) e.slots[i] = 'Nhập giờ bắt đầu và giờ kết thúc.';
      else if (mins(s.e) <= mins(s.s)) e.slots[i] = 'Giờ kết thúc phải sau giờ bắt đầu.';
    });
    f.slots.forEach((a, i) => f.slots.forEach((b, j) => {
      if (i < j && a.wd === b.wd && a.s && a.e && b.s && b.e && mins(a.s) < mins(b.e) && mins(b.s) < mins(a.e)) e.slots[j] = e.slots[j] || 'Các khung giờ trong cùng ngày không được trùng hoặc chồng lấn.';
    }));
    if (f.from && f.to) f.excl.forEach(x => { if (x.d < f.from || x.d > f.to) e.fields.excl = 'Ngày loại trừ phải nằm trong khoảng áp dụng của lịch học.'; });
    Object.values(e.fields).forEach(m => e.list.push(m));
    if (Object.keys(e.slots).length) e.list.push(`${Object.keys(e.slots).length} khung giờ chưa hợp lệ.`);
    return e;
  }

  const durShort = (s, e) => s && e && mins(e) > mins(s) ? hrs(mins(e) - mins(s)) : '';
  function renderDays(err){
    $('f-days').innerHTML = WD.map((n, wd) => {
      const idx = f.slots.map((s, i) => [s, i]).filter(([s]) => s.wd === wd);
      const on = idx.length > 0;
      const rows = idx.map(([s, i]) => `<div class="sch-slot"><input type="time" value="${s.s}" data-slot="${i}" data-k="s" aria-label="${n}: giờ bắt đầu" ${err.slots[i] && fTried ? 'aria-invalid="true"' : ''}><span class="sch-slot__sep" aria-hidden="true">–</span><input type="time" value="${s.e}" data-slot="${i}" data-k="e" aria-label="${n}: giờ kết thúc" ${err.slots[i] && fTried ? 'aria-invalid="true"' : ''}><span class="sch-slot__dur">${durShort(s.s, s.e)}</span>${idx.length > 1 ? `<button type="button" class="sch-icon-danger" data-slot-remove="${i}" aria-label="Gỡ khung giờ ${n} ${s.s}–${s.e}"><i data-lucide="trash-2" class="ui-icon" aria-hidden="true"></i></button>` : ''}${err.slots[i] && fTried ? `<span class="sch-error" style="flex-basis:100%">${err.slots[i]}</span>` : ''}</div>`).join('');
      return `<li class="sch-day ${on ? 'is-on' : ''}"><label class="ui-check sch-day__toggle"><input type="checkbox" data-day="${wd}" ${on ? 'checked' : ''}>${n}</label><div class="sch-day__slots">${on ? rows + `<button type="button" class="sch-day__add" data-day-add="${wd}" ${f.slots.length >= MAX_SLOTS ? 'disabled' : ''}><i data-lucide="plus" class="ui-icon" aria-hidden="true"></i>Thêm khung giờ ${n}</button>` : '<span class="sch-day__off">Không học</span>'}</div></li>`;
    }).join('');
    $('f-slots-count').textContent = `· ${f.slots.length} khung giờ`;
  }

  function renderExcl(plan){
    $('f-excl-count').textContent = `· ${f.excl.length}`;
    $('f-excl').hidden = !f.excl.length;
    $('f-excl').innerHTML = f.excl.map((x, i) => {
      const d = day(x.d), hit = plan.filter(p => p.excluded && iso(p.date) === x.d).length, out = f.from && f.to && (x.d < f.from || x.d > f.to);
      return `<li class="sch-excl-row"><span class="sch-excl-row__date">${dmy(d)}<small>${WD[wdOf(d)]}</small></span><span>${out ? '<span class="sch-badge" style="background:var(--sch-danger-bg);color:var(--sch-danger)">Ngoài khoảng áp dụng</span>' : hit ? `<span class="sch-badge sch-badge--warn">Bỏ ${hit} buổi</span>` : '<span class="sch-badge sch-badge--muted">Không trùng buổi học</span>'}</span><input value="${esc(x.r)}" data-excl-reason="${i}" placeholder="Lý do (không bắt buộc)" aria-label="Lý do loại trừ ${dmy(d)}" maxlength="120"><button type="button" class="sch-icon-danger" data-excl-remove="${i}" aria-label="Gỡ ngày loại trừ ${dmy(d)}"><i data-lucide="trash-2" class="ui-icon" aria-hidden="true"></i></button></li>`;
    }).join('');
  }

  function renderPreview(plan, err){
    const bad = Object.keys(err.slots).length, ok = f.from && f.to && f.from <= f.to && f.slots.length > bad; // invalid slots are left out, not the whole preview
    $('f-p-body').hidden = !ok; $('f-p-empty').hidden = ok;
    const ss = plan.filter(p => !p.excluded);
    $('f-p-count').textContent = ok ? ss.length : '—';
    $('f-p-hours').textContent = ok ? hrs(ss.reduce((t, p) => t + p.min, 0)) : '—';
    $('f-p-excl').textContent = ok ? `${plan.length - ss.length} buổi` : '—';
    $('f-p-diff').hidden = fMode !== 'edit' || !ok || ss.length === origCount;
    $('f-p-diff').innerHTML = `So với lịch đã lưu: <b>${origCount} → ${ss.length} buổi</b>`;
    $('f-preview-status').innerHTML = ok && bad ? `<span style="color:var(--sch-danger)">Chưa tính ${bad} khung giờ chưa hợp lệ</span>` : '';
    if (!ok) return;
    const from = day(f.from), to = day(f.to);
    if (!fMonth || fMonth < new Date(from.getFullYear(), from.getMonth(), 1) || fMonth > new Date(to.getFullYear(), to.getMonth(), 1)) fMonth = new Date(from.getFullYear(), from.getMonth(), 1);
    const first = fMonth, last = new Date(first.getFullYear(), first.getMonth() + 1, 0);
    $('f-month').textContent = `Tháng ${first.getMonth() + 1}/${first.getFullYear()}`;
    $('f-prev').disabled = first <= new Date(from.getFullYear(), from.getMonth(), 1);
    $('f-next').disabled = last >= new Date(to.getFullYear(), to.getMonth() + 1, 0);
    let html = WD_SHORT.map(d => `<div class="sch-cal__dow" role="columnheader">${d}</div>`).join('');
    for (let i = 0; i < wdOf(first); i++) html += '<div class="sch-cal__day is-blank" aria-hidden="true"><span class="sch-cal__num">.</span></div>';
    for (let d = new Date(first); d <= last; d.setDate(d.getDate() + 1)) {
      const out = d < from || d > to, items = plan.filter(p => iso(p.date) === iso(d)), pick = !out && items.length;
      const label = `${WD[wdOf(d)]}, ${dmy(d)}` + (out ? ', ngoài thời gian áp dụng' : items.length ? (items[0].excluded ? ': đã loại trừ, bấm để bỏ loại trừ' : `: ${items.length} buổi, bấm để loại trừ`) : '');
      html += `<div class="sch-cal__day ${out ? 'is-out' : ''} ${pick ? 'is-pick' : ''}" role="gridcell" ${pick ? `tabindex="0" data-pick="${iso(d)}"` : ''} aria-label="${esc(label)}"><span class="sch-cal__num">${d.getDate()}</span>` +
        items.map(p => p.excluded ? `<span class="sch-excluded"><s>${p.s}</s></span>` : `<span class="sch-session">${p.s}</span>`).join('') + '</div>';
    }
    const tail = (7 - (wdOf(first) + last.getDate()) % 7) % 7;
    for (let i = 0; i < tail; i++) html += '<div class="sch-cal__day is-blank" aria-hidden="true"><span class="sch-cal__num">.</span></div>';
    $('f-cal').innerHTML = html;
  }

  function renderForm(){
    const err = validate(), plan = planOf(f);
    $('f-name').value !== f.name && ($('f-name').value = f.name);
    $('f-tz').value = f.tz; $('f-from').value = f.from; $('f-to').value = f.to;
    const c = cohort();
    $('f-cohort').textContent = `Thời gian vận hành của lớp: ${dmy(day(c.from))} → ${dmy(day(c.to))}`;
    const show = (id, msg, inputs) => { $(id).hidden = !(fTried && msg); $(id).textContent = msg || ''; (inputs || []).forEach(x => fTried && msg ? $(x).setAttribute('aria-invalid', 'true') : $(x).removeAttribute('aria-invalid')); };
    show('f-name-err', err.fields.name, ['f-name']);
    show('f-period-err', err.fields.period, ['f-from', 'f-to']);
    show('f-slots-err', err.fields.slots);
    show('f-excl-err', err.fields.excl);
    renderDays(err); renderExcl(plan); renderPreview(plan, err);
    $('f-alert').hidden = !(fTried && err.list.length);
    $('f-alert-list').innerHTML = err.list.map(m => `<li>${esc(m)}</li>`).join('');
    const dirty = snap() !== fOrig, note = $('f-note');
    note.className = 'sch-footer-note' + (fTried && err.list.length ? ' is-error' : dirty ? ' is-dirty' : '');
    note.textContent = fTried && err.list.length ? `Còn ${err.list.length} mục cần sửa.` : dirty ? 'Có thay đổi chưa lưu.' : fMode === 'edit' ? 'Chưa có thay đổi.' : 'Lưu lịch không tạo Buổi học thực tế.';
    $('f-save').disabled = fMode === 'edit' && !dirty;
    icons();
  }

  function openForm(mode){
    fMode = mode; fTried = false; fMonth = null;
    const c = cohort();
    f = mode === 'edit' ? {name:cur.name, tz:cur.tz, from:cur.from, to:cur.to, slots:clone(cur.slots), excl:clone(cur.excl.map(x => ({d:x.d, r:x.r})))}
      : {name:'', tz:'Asia/Ho_Chi_Minh', from:c.from, to:c.to, slots:[], excl:[]};
    origCount = mode === 'edit' ? sessions().length : 0;
    fOrig = snap();
    $('sch-form-title').textContent = mode === 'edit' ? `Sửa lịch học: ${cur.name}` : 'Thêm lịch học';
    $('f-save').querySelector('span').textContent = mode === 'edit' ? 'Lưu thay đổi' : 'Lưu lịch học';
    $('f-name').value = f.name;
    if ($('sch-dialog').open) $('sch-dialog').close();
    renderForm();
    $('sch-form').showModal();
    $('f-name').focus();
  }
  function tryClose(){ if (snap() !== fOrig) $('sch-discard').showModal(); else $('sch-form').close(); }
  function toast(m){ const t = $('sch-toast'); t.textContent = m; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => t.hidden = true, 3000); }

  // Form events
  const form = $('sch-form');
  form.addEventListener('cancel', e => { e.preventDefault(); tryClose(); });
  form.addEventListener('click', e => {
    if (e.target === form) { tryClose(); return; }
    const t = e.target.closest('button,[data-pick]');
    if (!t) return;
    if (t.hasAttribute('data-form-cancel')) tryClose();
    else if (t.dataset.dayAdd) { const wd = +t.dataset.dayAdd, last = f.slots.filter(s => s.wd === wd).pop(); f.slots.push({wd, s:last?.e || '', e:''}); renderForm(); }
    else if (t.dataset.slotRemove) { f.slots.splice(+t.dataset.slotRemove, 1); renderForm(); }
    else if (t.dataset.exclRemove) { f.excl.splice(+t.dataset.exclRemove, 1); renderForm(); }
    else if (t.id === 'f-use-cohort') { f.from = cohort().from; f.to = cohort().to; renderForm(); }
    else if (t.id === 'f-prev') { fMonth = new Date(fMonth.getFullYear(), fMonth.getMonth() - 1, 1); renderForm(); }
    else if (t.id === 'f-next') { fMonth = new Date(fMonth.getFullYear(), fMonth.getMonth() + 1, 1); renderForm(); }
    else if (t.id === 'f-excl-add') addExcl();
    else if (t.dataset.pick) togglePick(t.dataset.pick);
  });
  form.addEventListener('keydown', e => { const t = e.target.closest('[data-pick]'); if (t && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); togglePick(t.dataset.pick); } });
  function togglePick(d){
    const i = f.excl.findIndex(x => x.d === d);
    if (i >= 0) { f.excl.splice(i, 1); toast(`Đã bỏ loại trừ ${dmy(day(d))}.`); }
    else if (f.excl.length < MAX_EXCL) { f.excl.push({d, r:''}); f.excl.sort((a, b) => a.d.localeCompare(b.d)); toast(`Đã loại trừ ${dmy(day(d))}. Có thể nhập lý do ở mục Ngày loại trừ.`); }
    renderForm();
    form.querySelector(`[data-pick="${d}"]`)?.focus();
  }
  function addExcl(){
    const d = $('f-excl-date').value, r = $('f-excl-reason').value.trim();
    if (!d) { $('f-excl-err').hidden = false; $('f-excl-err').textContent = 'Chọn ngày cần loại trừ.'; $('f-excl-date').focus(); return; }
    if (f.excl.some(x => x.d === d)) { $('f-excl-err').hidden = false; $('f-excl-err').textContent = `${dmy(day(d))} đã có trong danh sách.`; return; }
    f.excl.push({d, r}); f.excl.sort((a, b) => a.d.localeCompare(b.d));
    $('f-excl-date').value = ''; $('f-excl-reason').value = '';
    renderForm();
  }
  form.addEventListener('change', e => {
    const t = e.target;
    if (t.dataset.day) {
      const wd = +t.dataset.day;
      if (t.checked) { const tpl = f.slots[f.slots.length - 1]; f.slots.push({wd, s:tpl?.s || '', e:tpl?.e || ''}); f.slots.sort((a, b) => a.wd - b.wd || mins(a.s || '00:00') - mins(b.s || '00:00')); }
      else f.slots = f.slots.filter(s => s.wd !== wd);
      renderForm();
    } else if (t.dataset.slot) { f.slots[+t.dataset.slot][t.dataset.k] = t.value; renderForm(); }
    else if (t.id === 'f-tz') { f.tz = t.value; renderForm(); }
    else if (t.id === 'f-from' || t.id === 'f-to') { f[t.id === 'f-from' ? 'from' : 'to'] = t.value; renderForm(); }
  });
  form.addEventListener('input', e => {
    const t = e.target;
    if (t.id === 'f-name') { f.name = t.value; renderFormLight(); }
    else if (t.dataset.exclReason) { f.excl[+t.dataset.exclReason].r = t.value; renderFormLight(); }
  });
  function renderFormLight(){ // keep focus while typing: only refresh footer state
    const dirty = snap() !== fOrig, note = $('f-note');
    note.className = 'sch-footer-note' + (dirty ? ' is-dirty' : ''); note.textContent = dirty ? 'Có thay đổi chưa lưu.' : 'Chưa có thay đổi.';
    $('f-save').disabled = fMode === 'edit' && !dirty;
  }
  $('sch-form-el').addEventListener('submit', e => {
    e.preventDefault();
    fTried = true;
    const err = validate();
    if (err.list.length) { renderForm(); $('f-alert').focus(); return; }
    const tzLabel = {'Asia/Ho_Chi_Minh':'GMT+7', 'Asia/Seoul':'GMT+9', 'Asia/Tokyo':'GMT+9', 'UTC':'GMT+0'}[f.tz];
    const status = f.from > iso(TODAY) ? 'upcoming' : f.to < iso(TODAY) ? 'ended' : 'current';
    demos[curKey] = {name:f.name.trim(), status, from:f.from, to:f.to, tz:f.tz, tzLabel, slots:clone(f.slots).sort((a, b) => a.wd - b.wd), excl:clone(f.excl)};
    form.close();
    load(curKey);
    $('sch-dialog').showModal();
    toast(fMode === 'edit' ? 'Đã cập nhật lịch học. Không có Buổi học nào được tạo hoặc thay đổi.' : 'Đã lưu lịch học. Các Buổi học thực tế chưa được tạo.');
  });
  $('sch-discard').addEventListener('click', e => {
    const t = e.target.closest('button');
    if (!t) return;
    $('sch-discard').close();
    if (t.hasAttribute('data-discard-yes')) form.close();
  });
  root.addEventListener('click', e => { const t = e.target.closest('[data-open-form]'); if (t) openForm(t.dataset.openForm); });

  load('month');
  $('sch-dialog').showModal();
})();
