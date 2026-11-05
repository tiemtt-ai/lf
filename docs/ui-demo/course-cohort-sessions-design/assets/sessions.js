/* Static design interactions for Course cohort · Buổi học.
   All data is invented for the design (cohort 15 needs a login). Window rules for Trước / Trong / Sau are a
   PROPOSAL (LF-Core-Course lists exact windows as an open question) — see README "Quyết định cần Owner".
   Production keeps the server as the only authority for overlap, availability, reschedule and origin rules. */
(() => {
  const $ = id => document.getElementById(id);
  const root = $('ui-catalog-soft');
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icons = () => { if (window.lucide?.createIcons) window.lucide.createIcons(); };
  const pad = n => String(n).padStart(2, '0');
  const D = s => new Date(s);
  const isoD = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const hm = d => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
  const dm = d => `${pad(d.getDate())}/${pad(d.getMonth() + 1)}`;
  const dmy = d => `${dm(d)}/${d.getFullYear()}`;
  const dmhm = d => `${dm(d)} ${hm(d)}`;
  const WD = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
  const clone = o => JSON.parse(JSON.stringify(o));
  const NOW = D('2026-11-05T10:00');
  const COHORT = {start:D('2026-11-01T00:00'), end:D('2026-11-30T23:59')};
  const PH = {before:'Trước buổi', during:'Trong buổi', after:'Sau buổi'};
  const PH_SHORT = {before:'Trước', during:'Trong', after:'Sau'};
  const MODE = {online:'Online', offline:'Offline', hybrid:'Hybrid'};
  const ICON = {video:'video', doc:'file-text', live:'users', quiz:'list-checks', exercise:'square-check', survey:'clipboard-list', recording:'play-circle'};
  const TYPE = {video:'Video', doc:'Bài đọc', live:'Học trực tiếp', quiz:'Quiz', exercise:'Bài tập', survey:'Khảo sát', recording:'Xem lại'};

  const TEACHERS = [
    {id:1, name:'Kim Min-ji', role:'Giáo viên phụ trách lớp', busy:[['2026-11-13T12:00', '2026-11-13T13:30', 'lớp K11']]},
    {id:2, name:'Nguyễn Lan', role:'Giáo viên', busy:[]},
    {id:3, name:'Park Ji-hoon', role:'Trợ giảng', busy:[['2026-11-09T10:00', '2026-11-09T12:00', 'lớp K10']]}
  ];
  const A = (id, type, name, phases, req, rule) => ({id, type, name, phases, req, rule, live:type === 'live'});
  const LESSONS = [
    {id:1, title:'Bài 1: Giới thiệu bảng chữ Hangul', acts:[A(11, 'video', 'Hangul trong 10 phút', ['before'], true, 'xem ≥ 80% thời lượng'), A(12, 'doc', 'Bảng chữ cái', ['before'], false, 'mở tài liệu'), A(13, 'live', 'Làm quen Hangul', ['during'], true, 'điểm danh có mặt'), A(14, 'exercise', 'Luyện viết nguyên âm', ['after'], true, 'nộp bài')]},
    {id:2, title:'Bài 2: Nguyên âm và phụ âm', acts:[A(21, 'quiz', 'Khởi động phát âm', ['before'], true, 'đạt ≥ 70%'), A(22, 'live', 'Luyện phát âm', ['during'], true, 'điểm danh có mặt'), A(23, 'exercise', 'Ghép âm tiết', ['after'], true, 'nộp bài')]},
    {id:3, title:'Bài 3: Phụ âm cuối Patchim', acts:[A(31, 'video', 'Patchim cơ bản', ['before'], true, 'xem ≥ 80% thời lượng'), A(32, 'live', 'Thực hành Patchim', ['during'], true, 'điểm danh có mặt'), A(33, 'quiz', 'Kiểm tra Patchim', ['after'], true, 'đạt ≥ 70%'), A(34, 'survey', 'Cảm nhận buổi học', ['after'], false, 'hoàn thành')]},
    {id:4, title:'Bài 4: Chào hỏi và giới thiệu bản thân', acts:[A(41, 'video', 'Mẫu câu chào hỏi', ['before'], true, 'xem ≥ 80% thời lượng'), A(42, 'live', 'Hội thoại chào hỏi', ['during'], true, 'điểm danh có mặt'), A(43, 'exercise', 'Ghi âm giới thiệu bản thân', ['after'], true, 'nộp bài')]},
    {id:5, title:'Bài 5: Số đếm và thời gian', acts:[A(51, 'doc', 'Hai hệ số đếm', ['before'], true, 'mở tài liệu'), A(52, 'live', 'Hỏi giờ, hỏi giá', ['during'], true, 'điểm danh có mặt')]},
    {id:6, title:'Bài 6: Ôn tập giữa khóa', acts:[A(61, 'doc', 'Tổng hợp từ vựng bài 1–5', ['before'], false, 'mở tài liệu'), A(62, 'quiz', 'Bài kiểm tra giữa khóa', ['after'], true, 'đạt ≥ 60%')]},
    {id:7, title:'Bài 7: Mua sắm', acts:[A(71, 'video', 'Ở chợ và siêu thị', ['before'], true, 'xem ≥ 80% thời lượng'), A(72, 'live', 'Đóng vai mua bán', ['during'], true, 'điểm danh có mặt'), A(73, 'exercise', 'Viết đoạn hội thoại', ['after'], true, 'nộp bài')]},
    {id:8, title:'Bài 8: Giao tiếp tại nhà hàng', acts:[A(81, 'live', 'Gọi món bằng tiếng Hàn', ['during', 'after'], true, 'điểm danh có mặt'), A(82, 'exercise', 'Thực đơn của tôi', ['after'], true, 'nộp bài')]}
  ];
  const lesson = id => LESSONS.find(l => l.id === +id);
  const liveActs = l => l ? l.acts.filter(a => a.live) : [];

  // Schedule "lịch chính tháng 11": Mon 11–13, Tue 11–12, Fri 12–13, excluded 24/11.
  const OCC = [];
  for (let d = D('2026-11-01T00:00'); d <= COHORT.end; d.setDate(d.getDate() + 1)) {
    const slot = {1:['11:00', '13:00'], 2:['11:00', '12:00'], 5:['12:00', '13:00']}[d.getDay()];
    if (slot && isoD(d) !== '2026-11-24') OCC.push({id:'occ-' + isoD(d), start:`${isoD(d)}T${slot[0]}`, end:`${isoD(d)}T${slot[1]}`});
  }
  const occ = id => OCC.find(o => o.id === id);

  const SEED = [
    {id:1, type:'operational', title:'Buổi định hướng lớp', start:'2026-11-01T09:00', end:'2026-11-01T10:00', origin:null, status:'completed', mode:'online', link:'https://meet.google.com/abc-defg-hij', room:'', address:'', teachers:[2], history:[]},
    {id:2, type:'curriculum', lesson:1, activity:13, title:'Bài 1 · Làm quen Hangul', start:'2026-11-02T11:00', end:'2026-11-02T13:00', origin:'occ-2026-11-02', status:'completed', mode:'online', link:'https://meet.google.com/abc-defg-hij', room:'', address:'', teachers:[1], history:[]},
    {id:3, type:'curriculum', lesson:2, activity:22, title:'Bài 2 · Luyện phát âm', start:'2026-11-03T11:00', end:'2026-11-03T12:00', origin:'occ-2026-11-03', status:'completed', mode:'offline', link:'', room:'Phòng 201', address:'12 Nguyễn Huệ, Quận 1', teachers:[1, 3], history:[]},
    {id:4, type:'curriculum', lesson:3, activity:32, title:'Bài 3 · Thực hành Patchim', start:'2026-11-06T18:00', end:'2026-11-06T19:00', origin:'occ-2026-11-06', status:'scheduled', mode:'online', link:'https://zoom.us/j/9988776655', room:'', address:'', teachers:[1], history:[{at:'2026-11-04T09:12', by:'Visang 1', from:['2026-11-06T12:00', '2026-11-06T13:00'], to:['2026-11-06T18:00', '2026-11-06T19:00'], reason:'Giáo viên bận buổi trưa'}]},
    {id:5, type:'operational', title:'Phụ đạo phát âm', start:'2026-11-07T09:00', end:'2026-11-07T10:30', origin:null, status:'scheduled', mode:'offline', link:'', room:'Phòng 203', address:'12 Nguyễn Huệ, Quận 1', teachers:[3], history:[]},
    {id:6, type:'curriculum', lesson:4, activity:42, title:'Bài 4 · Hội thoại chào hỏi', start:'2026-11-09T11:00', end:'2026-11-09T13:00', origin:'occ-2026-11-09', status:'scheduled', mode:'online', link:'', room:'', address:'', teachers:[], history:[]},
    {id:7, type:'curriculum', lesson:5, activity:52, title:'Bài 5 · Hỏi giờ, hỏi giá', start:'2026-11-10T11:00', end:'2026-11-10T12:00', origin:'occ-2026-11-10', status:'scheduled', mode:'hybrid', link:'https://meet.google.com/xyz-uvw-rst', room:'Phòng 201', address:'12 Nguyễn Huệ, Quận 1', teachers:[2], history:[]}
  ];
  let S = clone(SEED), nextId = 8, view = 'time', filter = 'all', query = '', openRow = null;

  // ---------- derived ----------
  const active = s => !['cancelled', 'no_show'].includes(s.status);
  const relation = s => !s.origin ? 'off_schedule' : (occ(s.origin).start === s.start && occ(s.origin).end === s.end ? 'on_schedule' : 'rescheduled');
  const REL = {on_schedule:['ses-badge--outline', 'Theo lịch'], rescheduled:['ses-badge--warn', 'Đã điều chỉnh'], off_schedule:['ses-badge--muted', 'Ngoài lịch']};
  const STATUS = {scheduled:['', 'Đã lên lịch'], live:['ses-badge--success', 'Đang diễn ra'], completed:['ses-badge--muted', 'Đã diễn ra'], cancelled:['ses-badge--danger', 'Đã hủy'], no_show:['ses-badge--danger', 'Không diễn ra']};
  const teacher = id => TEACHERS.find(t => t.id === +id);
  const mins = (a, b) => Math.round((D(b) - D(a)) / 6e4);
  const dur = (a, b) => { const m = mins(a, b), h = Math.floor(m / 60), r = m % 60; return h ? `${h} giờ${r ? ' ' + r + ' phút' : ''}` : `${r} phút`; };
  const editable = s => s.status === 'scheduled' && D(s.start) > NOW;
  const issues = s => {
    if (!active(s) || s.status === 'completed') return [];
    const out = [];
    if (!s.teachers.length) out.push('Chưa phân công giáo viên');
    if (s.mode !== 'offline' && !s.link) out.push('Chưa có liên kết tham gia');
    if (s.mode !== 'online' && (!s.room || !s.address)) out.push('Thiếu phòng học / địa chỉ');
    return out;
  };

  // PROPOSAL: windows of a curriculum Session. Trước opens when the previous curriculum Session ends (or the cohort
  // starts) and closes at the start of the lesson's first Session; Sau opens at the end of the lesson's last Session
  // and is due when the next curriculum Session starts (or the cohort ends). A lesson with several Sessions shares
  // one Trước (first Session) and one Sau (last Session).
  function windows(list, s){
    const cur = list.filter(x => x.type === 'curriculum' && active(x)).sort((a, b) => D(a.start) - D(b.start));
    const same = cur.filter(x => x.lesson === s.lesson), first = same[0] || s, last = same[same.length - 1] || s;
    const iF = cur.indexOf(first), iL = cur.indexOf(last);
    const prev = cur[iF - 1], next = cur[iL + 1];
    return {
      before:{open:prev ? D(prev.end) : COHORT.start, close:D(first.start), rule:prev ? `Từ khi buổi trước kết thúc (${esc(prev.title)}) đến lúc vào buổi` : 'Từ khi lớp bắt đầu đến lúc vào buổi', shared:same.length > 1 && first !== s ? first : null},
      during:{open:D(s.start), close:D(s.end), rule:'Theo giờ của buổi học'},
      after:{open:D(last.end), close:next ? D(next.start) : COHORT.end, rule:next ? `Đến khi buổi kế tiếp bắt đầu (${esc(next.title)})` : 'Đến khi lớp kết thúc', shared:same.length > 1 && last !== s ? last : null},
      prev, next, count:same.length
    };
  }
  const winText = (w, ph) => ph === 'during' ? `${dmhm(w.open)} → ${hm(w.close)}` : `Mở ${dmhm(w.open)} → Hạn ${dmhm(w.close)}`;

  function phasesHtml(l, w, compact){
    if (!l) return '';
    return `<div class="ses-phases">` + ['before', 'during', 'after'].map(ph => {
      const acts = l.acts.filter(a => a.phases.includes(ph));
      const win = w[ph];
      return `<div class="ses-phase ses-phase--${ph}"><div class="ses-phase__head"><span class="ses-ph ses-ph--${acts.length ? ph : 'none'}">${PH[ph]}</span><span class="ui-caption" style="font-size:12px">${acts.length} hoạt động</span></div>` +
        (acts.length ? `<div class="ses-phase__win"><i data-lucide="clock" class="ui-icon" aria-hidden="true"></i><span>${winText(win, ph)}</span></div><div class="ses-phase__rule">${win.shared ? `Dùng chung với buổi ${dmy(D(win.shared.start))} của cùng bài học` : win.rule}</div>` +
          (compact ? '' : `<ul class="ses-acts">${acts.map(a => `<li><i data-lucide="${ICON[a.type]}" class="ui-icon" aria-hidden="true"></i><span class="ses-acts__body">${TYPE[a.type]} · ${esc(a.name)}<small>Hoàn thành: ${esc(a.rule)}</small></span><span class="${a.req ? 'ses-req' : 'ses-opt'}">${a.req ? 'Bắt buộc' : 'Tùy chọn'}</span></li>`).join('')}</ul>`)
          : `<p class="ses-phase__empty">Bài học không có hoạt động ${PH[ph].toLowerCase()}.</p>`) + `</div>`;
    }).join('') + `</div>`;
  }
  const phaseChips = l => l ? `<span class="ses-phs">${['before', 'during', 'after'].map(ph => { const n = l.acts.filter(a => a.phases.includes(ph)).length; return `<span class="ses-ph ses-ph--${n ? ph : 'none'}">${PH_SHORT[ph]} ${n || '—'}</span>`; }).join('')}</span>` : '';

  // ---------- page ----------
  function counts(){
    const ghosts = OCC.filter(o => !S.some(s => s.origin === o.id) && D(o.start) > NOW);
    return {all:S.length + ghosts.length, upcoming:S.filter(s => active(s) && D(s.start) > NOW).length, past:S.filter(s => D(s.start) <= NOW).length, issues:S.filter(s => issues(s).length).length, ghost:ghosts.length};
  }
  function renderSummary(){
    const withLive = LESSONS.filter(l => liveActs(l).length), covered = withLive.filter(l => S.some(s => s.lesson === l.id && active(s)));
    const upcoming = S.filter(s => active(s) && D(s.start) > NOW).sort((a, b) => D(a.start) - D(b.start))[0];
    const c = counts();
    $('ses-summary').innerHTML =
      `<div><dt>Bài học đã có buổi</dt><dd>${covered.length}/${withLive.length}<small>bài có hoạt động trực tiếp</small></dd><div class="ses-bar" aria-hidden="true"><span style="width:${Math.round(covered.length / withLive.length * 100)}%"></span></div></div>` +
      `<div><dt>Buổi học</dt><dd>${S.filter(active).length} buổi<small>${c.past} đã diễn ra · ${c.upcoming} sắp tới</small></dd></div>` +
      `<div><dt>Cần xử lý</dt><dd style="color:${c.issues ? 'var(--ses-warn)' : 'inherit'}">${c.issues} buổi<small>${c.ghost} ngày dự kiến chưa tạo</small></dd></div>` +
      `<div><dt>Buổi tiếp theo</dt><dd>${upcoming ? `${WD[D(upcoming.start).getDay()]}, ${dm(D(upcoming.start))} · ${hm(D(upcoming.start))}<small>${esc(upcoming.title)}</small>` : '—'}</dd></div>`;
    const F = [['all', 'Tất cả', c.all], ['upcoming', 'Sắp diễn ra', c.upcoming], ['past', 'Đã diễn ra', c.past], ['issues', 'Cần xử lý', c.issues], ['ghost', 'Chưa tạo từ lịch', c.ghost]];
    $('ses-filters').innerHTML = F.map(([k, n, v]) => `<button type="button" class="ses-chip" data-filter="${k}" aria-pressed="${filter === k}">${n}<span>${v}</span></button>`).join('');
  }
  const match = (s, l) => !query || (s.title + ' ' + (l?.title || '') + ' ' + s.teachers.map(t => teacher(t).name).join(' ')).toLocaleLowerCase('vi').includes(query.toLocaleLowerCase('vi'));

  function sessionRow(s){
    const l = lesson(s.lesson), rel = REL[relation(s)], st = STATUS[s.status], iss = issues(s), open = openRow === s.id;
    const st0 = D(s.start), en = D(s.end), o = s.origin && relation(s) === 'rescheduled' ? occ(s.origin) : null;
    const act = l?.acts.find(a => a.id === s.activity);
    return `<li><div class="ses-row ${open ? 'is-open' : ''} ${active(s) ? '' : 'is-cancelled'}">` +
      `<div class="ses-when"><strong>${dmy(st0)}</strong><strong>${hm(st0)}–${hm(en)}</strong><span class="ui-caption">${WD[st0.getDay()]}${o ? ` · <span class="ses-was" title="Giờ theo lịch">${hm(D(o.start))}–${hm(D(o.end))}</span>` : ''}</span></div>` +
      `<div class="ses-col-content"><span class="ses-row__title">${esc(s.title)}</span><span class="ses-sub">${s.type === 'curriculum' ? `${esc(l.title)} · ${esc(act?.name || '')}` : 'Ngoài chương trình · không tính tiến độ'}</span><div class="ses-row__meta">${s.type === 'curriculum' ? phaseChips(l) : ''}<span class="ses-badge ${rel[0]}">${rel[1]}</span></div></div>` +
      `<div><span>${MODE[s.mode]}</span><span class="ses-sub ${s.teachers.length ? '' : 'ses-unassigned'}">${s.teachers.length ? s.teachers.map(t => esc(teacher(t).name)).join(', ') : 'Chưa phân công'}</span></div>` +
      `<div><span class="ses-badge ${st[0]}">${st[1]}</span>${iss.length ? `<span class="ses-sub" style="color:var(--ses-warn)">${iss.map(esc).join(' · ')}</span>` : ''}</div>` +
      `<div class="ses-actions">${s.type === 'curriculum' ? `<button type="button" class="ses-iconbtn" data-expand="${s.id}" aria-expanded="${open}" aria-label="${open ? 'Ẩn' : 'Xem'} nội dung Trước/Trong/Sau của ${esc(s.title)}"><i data-lucide="chevron-down" class="ui-icon" aria-hidden="true"></i></button>` : ''}<button type="button" class="ses-iconbtn" data-edit="${s.id}" aria-label="${editable(s) ? 'Sửa' : 'Xem'} buổi ${esc(s.title)}" data-tooltip="${editable(s) ? 'Sửa buổi học' : 'Xem buổi học'}"><i data-lucide="${editable(s) ? 'square-pen' : 'eye'}" class="ui-icon" aria-hidden="true"></i></button></div>` +
      `</div>${open && l ? `<div class="ses-expand">${phasesHtml(l, windows(S, s))}</div>` : ''}</li>`;
  }
  function ghostRow(o){
    const st0 = D(o.start), en = D(o.end);
    return `<li><div class="ses-row is-ghost"><div class="ses-when"><strong>${dmy(st0)}</strong><strong>${hm(st0)}–${hm(en)}</strong><span class="ui-caption">${WD[st0.getDay()]}</span></div>` +
      `<div class="ses-col-content"><span class="ses-row__title" style="color:#6e7787">Ngày dự kiến từ lịch học</span><span class="ses-sub">lịch chính tháng 11 · chưa tạo buổi học</span></div><div></div><div><span class="ses-badge ses-badge--outline">Dự kiến</span></div>` +
      `<div class="ses-actions"><button type="button" class="ui-button ui-button--outline ui-button--sm" data-add-occ="${o.id}"><i data-lucide="plus" class="ui-icon" aria-hidden="true"></i>Tạo buổi</button></div></div></li>`;
  }
  function renderTime(){
    let items = S.map(s => ({at:D(s.start), s})).concat(OCC.filter(o => !S.some(s => s.origin === o.id) && D(o.start) > NOW).map(o => ({at:D(o.start), o})));
    items = items.filter(x => {
      if (x.o) return (filter === 'all' || filter === 'ghost') && !query;
      const s = x.s, l = lesson(s.lesson);
      if (!match(s, l)) return false;
      return filter === 'all' || (filter === 'upcoming' && active(s) && D(s.start) > NOW) || (filter === 'past' && D(s.start) <= NOW) || (filter === 'issues' && issues(s).length);
    }).sort((a, b) => a.at - b.at);
    let html = '', week = null;
    items.forEach(x => {
      const mon = new Date(x.at); mon.setDate(mon.getDate() - ((mon.getDay() + 6) % 7)); mon.setHours(0, 0, 0, 0);
      if (+mon !== week) { const sun = new Date(mon); sun.setDate(sun.getDate() + 6); html += `<li class="ses-week"><span>Tuần ${dm(mon)} – ${dmy(sun)}</span><span>${items.filter(y => { const m = new Date(y.at); m.setDate(m.getDate() - ((m.getDay() + 6) % 7)); m.setHours(0, 0, 0, 0); return +m === +mon && y.s; }).length} buổi</span></li>`; week = +mon; }
      html += x.s ? sessionRow(x.s) : ghostRow(x.o);
    });
    $('ses-list').innerHTML = html || '<li class="ses-empty">Không có buổi học phù hợp.</li>';
  }
  function renderProgram(){
    const html = LESSONS.map((l, i) => {
      const live = liveActs(l), ss = S.filter(s => s.lesson === l.id).sort((a, b) => D(a.start) - D(b.start));
      if (query && !(l.title.toLocaleLowerCase('vi').includes(query.toLocaleLowerCase('vi')))) return '';
      const body = !live.length
        ? `<div class="ses-note ses-note--warn" style="margin-top:6px"><i data-lucide="triangle-alert" class="ui-icon" aria-hidden="true"></i><span>Bài không có Hoạt động học trực tiếp nên không gắn được buổi trong chương trình. Hoạt động Trước/Sau của bài chưa có mốc mở — <b>cần Owner quyết định</b>.</span></div>`
        : ss.length ? `<div class="ses-lesson__sessions">${ss.map(s => `<button type="button" class="ses-pill ${s.status === 'completed' ? 'is-done' : ''}" data-edit="${s.id}"><i data-lucide="${s.status === 'completed' ? 'check' : 'calendar'}" class="ui-icon" aria-hidden="true" style="width:12px;height:12px"></i>${WD[D(s.start).getDay()]} ${dm(D(s.start))} · ${hm(D(s.start))}</button>`).join('')}</div>`
          : `<span class="ses-sub" style="color:var(--ses-warn)">Chưa có buổi học</span>`;
      return `<li class="ses-lesson"><span class="ses-lesson__no">${i + 1}</span><div><span class="ses-row__title">${esc(l.title)}</span><div class="ses-row__meta">${phaseChips(l)}${ss.length > 1 ? `<span class="ses-badge">${ss.length} buổi</span>` : ''}</div>${body}</div>` +
        `<div>${live.length ? `<button type="button" class="ui-button ui-button--sm ${ss.length ? '' : 'ui-button--outline'}" data-add-lesson="${l.id}"><i data-lucide="plus" class="ui-icon" aria-hidden="true"></i>${ss.length ? 'Thêm buổi' : 'Xếp buổi'}</button>` : ''}</div></li>`;
    }).join('');
    const ops = S.filter(s => s.type === 'operational');
    $('ses-list').innerHTML = (html || '<li class="ses-empty">Không có bài học phù hợp.</li>') + (ops.length ? `<li class="ses-week"><span>Buổi ngoài chương trình</span><span>${ops.length} buổi</span></li>` + ops.map(sessionRow).join('') : '');
  }
  function render(){
    renderSummary();
    root.querySelectorAll('[data-view]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.view === view)));
    $('ses-filters').hidden = view !== 'time';
    view === 'time' ? renderTime() : renderProgram();
    icons();
  }

  // ---------- validation shared by edit / add ----------
  const at = (date, time) => date && time ? `${date}T${time}` : '';
  function check(d, selfId){
    const e = {};
    if (d.type === 'curriculum' && !d.lesson) e.lesson = 'Chọn bài học.';
    if (d.type === 'curriculum' && d.lesson && !d.activity) e.activity = 'Chọn hoạt động học trực tiếp.';
    if (!d.title.trim()) e.title = 'Nhập tên buổi học.';
    const st = at(d.date, d.t1), en = at(d.date, d.t2);
    if (!st || !en) e.time = 'Chọn ngày, giờ bắt đầu và giờ kết thúc.';
    else if (D(en) <= D(st)) e.time = 'Giờ kết thúc phải sau giờ bắt đầu.';
    else if (D(st) < COHORT.start || D(en) > COHORT.end) e.time = 'Buổi học phải nằm trong thời gian vận hành của lớp (01/11/2026 → 30/11/2026).';
    else if (d.timeChanged !== false && D(st) <= NOW) e.time = 'Thời gian bắt đầu phải sau thời điểm hiện tại.';
    else { const clash = S.find(x => x.id !== selfId && active(x) && D(x.start) < D(en) && D(st) < D(x.end)); if (clash) e.time = `Trùng giờ với buổi “${clash.title}” (${dmhm(D(clash.start))}–${hm(D(clash.end))}).`; }
    if (d.mode !== 'online' && (!d.room.trim() || !d.address.trim())) e.place = 'Buổi offline hoặc hybrid cần phòng học và địa chỉ.';
    if (st && en && !e.time) d.teachers.forEach(t => { const b = busy(teacher(t), st, en); if (b) e.teachers = `${teacher(t).name} bận ${hm(D(b[0]))}–${hm(D(b[1]))} (${b[2]}).`; });
    return e;
  }
  const busy = (t, st, en) => t.busy.find(b => D(b[0]) < D(en) && D(st) < D(b[1]));
  const errOf = (e, k, tried) => tried && e[k] ? `<span class="ses-error" role="alert">${esc(e[k])}</span>` : '';
  const inv = (e, k, tried) => tried && e[k] ? 'aria-invalid="true"' : '';

  function deliveryFields(d, e, tried, ro){
    if (ro) return `<p class="ses-readonly">${MODE[d.mode]}${d.link ? ` · <a class="ses-link" href="#">${esc(d.link)}</a>` : ''}${d.room ? ` · ${esc(d.room)}, ${esc(d.address)}` : ''}</p>`;
    return `<div class="ses-cards" style="--n:3">${Object.entries({offline:'Giờ cố định, tại phòng học', online:'Giờ cố định, có liên kết', hybrid:'Phòng học và liên kết'}).map(([k, h]) => `<label class="ses-card"><input type="radio" name="mode" value="${k}" data-f="mode" ${d.mode === k ? 'checked' : ''}><span><strong>${MODE[k]}</strong><small>${h}</small></span></label>`).join('')}</div>` +
      `<div class="ses-grid">` +
      (d.mode !== 'offline' ? `<div class="ui-field is-full"><label class="ui-field__label" for="f-link">Liên kết tham gia</label><input id="f-link" type="url" data-f="link" value="${esc(d.link)}" placeholder="Dán liên kết tham gia buổi học"><span class="ui-caption" style="font-size:12px">${d.type === 'curriculum' ? 'Tự điền link mặc định của hoạt động nếu có. Có thể bổ sung sau; học viên thấy “Chưa có thông tin” đến khi có link.' : 'Không bắt buộc. Có thể bổ sung sau.'}</span></div>` : '') +
      (d.mode !== 'online' ? `<div class="ui-field"><label class="ui-field__label" for="f-room"><span class="ui-field__required">*</span>Phòng học</label><input id="f-room" data-f="room" value="${esc(d.room)}" placeholder="Ví dụ: Phòng 201" ${inv(e, 'place', tried)}></div><div class="ui-field"><label class="ui-field__label" for="f-addr"><span class="ui-field__required">*</span>Địa chỉ</label><input id="f-addr" data-f="address" value="${esc(d.address)}" placeholder="Nhập địa chỉ tổ chức buổi học" ${inv(e, 'place', tried)}></div><div class="is-full">${errOf(e, 'place', tried)}</div>` : '') + `</div>`;
  }
  function teacherFields(d, e, tried, ro){
    const st = at(d.date, d.t1), en = at(d.date, d.t2);
    if (ro) return `<p class="ses-readonly">${d.teachers.length ? d.teachers.map(t => esc(teacher(t).name)).join(', ') : 'Chưa phân công'}</p>`;
    return `<div class="ses-cards" style="--n:3">${TEACHERS.map(t => { const b = st && en ? busy(t, st, en) : null; return `<label class="ses-card"><input type="checkbox" data-teacher="${t.id}" ${d.teachers.includes(t.id) ? 'checked' : ''} ${b && !d.teachers.includes(t.id) ? 'disabled' : ''}><span><strong>${esc(t.name)}</strong><small>${esc(t.role)}</small><small style="color:${b ? 'var(--ses-danger)' : 'var(--ses-success)'}">${!st || !en ? 'Chọn thời gian để kiểm tra' : b ? `Bận ${hm(D(b[0]))}–${hm(D(b[1]))} · ${b[2]}` : 'Rảnh trong khung giờ'}</small></span></label>`; }).join('')}</div>` +
      errOf(e, 'teachers', tried) + (d.teachers.length ? '' : '<span class="ui-caption" style="font-size:12px;color:var(--ses-warn)">Có thể lưu khi chưa phân công; buổi chưa có giáo viên không nhận điểm danh hay ghi hình.</span>');
  }
  function timeFields(d, e, tried, ro, lockedNote){
    if (ro) return `<p class="ses-readonly">${WD[D(at(d.date, d.t1)).getDay()]}, ${dmy(D(at(d.date, d.t1)))} · ${d.t1}–${d.t2} <span class="ui-caption">(${dur(at(d.date, d.t1), at(d.date, d.t2))})</span></p>${lockedNote ? `<span class="ui-caption" style="font-size:12px">${lockedNote}</span>` : ''}`;
    return `<div class="ses-grid" style="grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) minmax(0,1fr)"><div class="ui-field"><label class="ui-field__label" for="f-date"><span class="ui-field__required">*</span>Ngày</label><input id="f-date" type="date" data-f="date" value="${d.date}" min="2026-11-01" max="2026-11-30" ${inv(e, 'time', tried)}></div><div class="ui-field"><label class="ui-field__label" for="f-t1"><span class="ui-field__required">*</span>Bắt đầu</label><input id="f-t1" type="time" data-f="t1" value="${d.t1}" ${inv(e, 'time', tried)}></div><div class="ui-field"><label class="ui-field__label" for="f-t2"><span class="ui-field__required">*</span>Kết thúc</label><input id="f-t2" type="time" data-f="t2" value="${d.t2}" ${inv(e, 'time', tried)}></div></div>` +
      errOf(e, 'time', tried) + `<span class="ui-caption" style="font-size:12px">Múi giờ Asia/Ho_Chi_Minh · trong thời gian vận hành 01/11 → 30/11/2026 · không trùng buổi khác của lớp.</span>`;
  }

  // ---------- edit dialog ----------
  let E = null;
  function openEdit(id){
    const s = S.find(x => x.id === +id);
    E = {s, d:{type:s.type, lesson:s.lesson, activity:s.activity, title:s.title, date:s.start.slice(0, 10), t1:s.start.slice(11), t2:s.end.slice(11), reason:'', mode:s.mode, link:s.link, room:s.room, address:s.address, teachers:[...s.teachers]}, tried:false};
    E.orig = JSON.stringify(E.d);
    renderEdit(); $('ses-edit').showModal();
  }
  function renderEdit(){
    const {s, d} = E, ro = !editable(s), l = lesson(d.lesson);
    const st = at(d.date, d.t1), en = at(d.date, d.t2), timeChanged = st !== s.start || en !== s.end;
    d.timeChanged = timeChanged;
    const e = ro ? {} : check(d, s.id), dirty = JSON.stringify({...d, timeChanged:undefined}) !== JSON.stringify({...JSON.parse(E.orig), timeChanged:undefined});
    $('ses-edit-title').innerHTML = `${esc(s.title)} <span class="ses-badge ${STATUS[s.status][0]}">${STATUS[s.status][1]}</span> <span class="ses-badge ${REL[relation(s)][0]}">${REL[relation(s)][1]}</span>`;
    $('ses-edit-sub').textContent = ro ? 'Buổi đã bắt đầu hoặc đã diễn ra — chỉ xem. Giữ nguyên để bảo toàn lịch sử điểm danh, ghi hình và tiến độ.' : 'Sửa nội dung, thời gian, hình thức và giáo viên của buổi chưa diễn ra.';
    // hypothetical list for windows + impact
    const hyp = S.map(x => x.id === s.id ? {...x, lesson:d.lesson, start:st && en && !e.time ? st : x.start, end:st && en && !e.time ? en : x.end} : x);
    const me = hyp.find(x => x.id === s.id);
    const impact = [];
    if (timeChanged && !e.time && s.type === 'curriculum') {
      const before = windows(S, s), after = windows(hyp, me);
      [['prev', 'after', 'Sau buổi', 'close'], ['next', 'before', 'Trước buổi', 'open']].forEach(([k, ph, label, edge]) => {
        const nb0 = before[k], nb1 = after[k];
        if (nb1 && nb1.type === 'curriculum' && lesson(nb1.lesson)?.acts.some(a => a.phases.includes(ph))) {
          const w0 = windows(S, S.find(x => x.id === nb1.id))[ph][edge], w1 = windows(hyp, nb1)[ph][edge];
          if (+w0 !== +w1) impact.push(`${esc(nb1.title)} · ${label}: ${edge === 'close' ? 'hạn' : 'mở'} <s>${dmhm(w0)}</s> → <b>${dmhm(w1)}</b>`);
        }
        if (nb0 && nb1 && nb0.id !== nb1.id) impact.push(`Thứ tự buổi thay đổi: buổi liền kề giờ là “${esc(nb1.title)}”.`);
      });
    }
    const content = s.type === 'curriculum'
      ? (ro ? `<p class="ses-readonly">${esc(l.title)} · ${esc(l.acts.find(a => a.id === d.activity)?.name)}</p>` :
        `<div class="ses-grid"><div class="ui-field"><label class="ui-field__label" for="f-lesson"><span class="ui-field__required">*</span>Bài học</label><select id="f-lesson" data-f="lesson">${LESSONS.map(x => `<option value="${x.id}" ${x.id === d.lesson ? 'selected' : ''} ${liveActs(x).length ? '' : 'disabled'}>${esc(x.title)}${liveActs(x).length ? '' : ' (không có hoạt động trực tiếp)'}</option>`).join('')}</select></div><div class="ui-field"><label class="ui-field__label" for="f-act"><span class="ui-field__required">*</span>Hoạt động học trực tiếp</label><select id="f-act" data-f="activity">${liveActs(l).map(a => `<option value="${a.id}" ${a.id === d.activity ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}</select></div></div>`)
      : `<p class="ses-readonly">Buổi ngoài chương trình · không gắn bài học, không mở hoạt động, không tính tiến độ.</p>`;
    const history = s.history.length ? `<section><header><h4>Lịch sử dời lịch</h4></header>${s.history.map(h => `<dl class="ses-diff"><dt>${dmhm(D(h.at))}</dt><dd><s>${dmhm(D(h.from[0]))}–${hm(D(h.from[1]))}</s> → ${dmhm(D(h.to[0]))}–${hm(D(h.to[1]))} · ${esc(h.by)}${h.reason ? ` · “${esc(h.reason)}”` : ''}</dd></dl>`).join('')}</section>` : '';
    $('ses-edit-body').innerHTML = `<div class="ses-split"><div class="ses-form">` +
      `<section><header><h4>Nội dung</h4></header>${content}<div class="ui-field"><label class="ui-field__label" for="f-title"><span class="ui-field__required">*</span>Tên buổi học</label>${ro ? `<p class="ses-readonly">${esc(d.title)}</p>` : `<input id="f-title" data-f="title" value="${esc(d.title)}" maxlength="255" ${inv(e, 'title', E.tried)}>${errOf(e, 'title', E.tried)}`}</div></section>` +
      `<section><header><h4>Thời gian</h4>${s.origin ? `<span class="ui-caption">Giờ theo lịch học: ${dmhm(D(occ(s.origin).start))}–${hm(D(occ(s.origin).end))}. Đổi giờ vẫn giữ nguồn lịch học và ghi lịch sử dời lịch.</span>` : '<span class="ui-caption">Buổi tạo ngoài lịch học.</span>'}</header>${timeFields(d, e, E.tried, ro)}` +
      (!ro && timeChanged ? `<div class="ses-note ses-note--warn"><i data-lucide="history" class="ui-icon" aria-hidden="true"></i><div><b>Dời lịch:</b> <s>${dmhm(D(s.start))}–${hm(D(s.end))}</s> → ${st && en ? `${dmhm(D(st))}–${hm(D(en))}` : '…'}${s.origin ? ' · nhãn đổi thành “Đã điều chỉnh”' : ''}.<div class="ui-field" style="margin-top:8px"><label class="ui-field__label" for="f-reason">Lý do dời lịch</label><input id="f-reason" data-f="reason" value="${esc(d.reason)}" maxlength="1000" placeholder="Ví dụ: Giáo viên bận, lớp đề nghị đổi giờ"><span class="ui-caption" style="font-size:12px">Lưu vào lịch sử, học viên không thấy.</span></div></div></div>` : '') +
      (impact.length ? `<div class="ses-note"><i data-lucide="git-compare" class="ui-icon" aria-hidden="true"></i><div><b>Ảnh hưởng tới buổi liền kề</b><ul>${impact.map(x => `<li>${x}</li>`).join('')}</ul></div></div>` : '') + `</section>` +
      `<section><header><h4>Hình thức</h4></header>${deliveryFields(d, e, E.tried, ro)}</section>` +
      `<section><header><h4>Giáo viên</h4></header>${teacherFields(d, e, E.tried, ro)}</section>${history}</div>` +
      `<aside class="ses-side"><div class="ses-side__head"><div><h4>Nội dung buổi học</h4><span class="ui-caption">${s.type === 'curriculum' ? 'Từ template phiên bản đã khóa · chỉ xem. Mốc mở tính theo giờ buổi học.' : 'Buổi ngoài chương trình.'}</span></div></div>` +
      (s.type === 'curriculum' ? phasesHtml(l, windows(hyp, me)) + `<p class="ui-caption" style="font-size:12px">Quy tắc mốc Trước/Sau là đề xuất, chờ Owner duyệt.</p>` : `<div class="ses-note"><i data-lucide="info" class="ui-icon" aria-hidden="true"></i><span>Không gắn bài học nên không mở hoạt động Trước/Trong/Sau và không ảnh hưởng tiến độ hay hoàn thành khóa học.</span></div>`) + `</aside></div>`;
    const errs = Object.keys(e).length;
    const note = $('ses-edit-note');
    note.className = 'ses-footer-note' + (E.tried && errs ? ' is-error' : dirty ? ' is-dirty' : '');
    note.textContent = ro ? '' : E.tried && errs ? `Còn ${errs} mục cần sửa.` : dirty ? (timeChanged ? 'Có thay đổi chưa lưu · gồm dời lịch.' : 'Có thay đổi chưa lưu.') : 'Chưa có thay đổi.';
    $('ses-edit-save').hidden = ro; $('ses-edit-save').disabled = !dirty;
    icons();
  }

  // ---------- add dialog ----------
  let N = null;
  function openAdd(opts = {}){
    const o = opts.occ ? occ(opts.occ) : null;
    const l = opts.lesson ? lesson(opts.lesson) : null;
    N = {occ:o, tried:false, titleDirty:false, d:{type:'curriculum', lesson:l?.id || null, activity:l ? liveActs(l)[0]?.id : null, title:'', date:o ? o.start.slice(0, 10) : '2026-11-14', t1:o ? o.start.slice(11) : '09:00', t2:o ? o.end.slice(11) : '10:30', mode:'online', link:'', room:'', address:'', teachers:[]}};
    if (!N.d.lesson && o) { const nl = nextUnscheduled(); if (nl) { N.d.lesson = nl.id; N.d.activity = liveActs(nl)[0].id; } }
    suggestTitle();
    $('ses-add-sub').textContent = o ? `Theo lịch học · ${WD[D(o.start).getDay()]}, ${dmy(D(o.start))} ${hm(D(o.start))}–${hm(D(o.end))} — giờ lấy chính xác từ ngày dự kiến.` : 'Buổi ngoài lịch học — tự nhập thời gian. Buổi được tạo ở trạng thái Đã lên lịch.';
    renderAdd(); $('ses-add').showModal();
  }
  const nextUnscheduled = () => LESSONS.find(l => liveActs(l).length && !S.some(s => s.lesson === l.id && active(s)));
  function suggestTitle(){ if (N.titleDirty) return; const l = lesson(N.d.lesson), a = l?.acts.find(x => x.id === N.d.activity); N.d.title = N.d.type === 'curriculum' && l ? `${l.title.split(':')[0]} · ${a?.name || ''}` : ''; }
  function renderAdd(){
    const {d} = N, l = lesson(d.lesson), e = check(d, null), st = at(d.date, d.t1), en = at(d.date, d.t2);
    const existing = l ? S.filter(s => s.lesson === l.id && active(s)) : [];
    const hyp = st && en && !e.time && l ? S.concat([{id:-1, type:'curriculum', lesson:l.id, start:st, end:en, status:'scheduled', title:d.title || '(buổi mới)'}]) : null;
    $('ses-add-body').innerHTML = `<div class="ses-split"><div class="ses-form">` +
      `<section><header><h4>Loại buổi học</h4></header><div class="ses-cards"><label class="ses-card"><input type="radio" name="type" value="curriculum" data-f="type" ${d.type === 'curriculum' ? 'checked' : ''}><span><strong>Buổi trong chương trình</strong><small>Gắn một bài học và hoạt động học trực tiếp; mở các hoạt động Trước/Trong/Sau.</small></span></label><label class="ses-card"><input type="radio" name="type" value="operational" data-f="type" ${d.type === 'operational' ? 'checked' : ''}><span><strong>Buổi ngoài chương trình</strong><small>Định hướng, họp lớp, phụ đạo, sự kiện; không tính tiến độ.</small></span></label></div></section>` +
      (d.type === 'curriculum' ? `<section><header><h4>Bài học</h4><span class="ui-caption">Chỉ bài có Hoạt động học trực tiếp mới chọn được. Một bài có thể có nhiều buổi.</span></header><div class="ses-grid"><div class="ui-field"><label class="ui-field__label" for="f-lesson"><span class="ui-field__required">*</span>Bài học</label><select id="f-lesson" data-f="lesson" ${inv(e, 'lesson', N.tried)}><option value="">Chọn bài học</option>${LESSONS.map(x => { const n = S.filter(s => s.lesson === x.id && active(s)).length; return `<option value="${x.id}" ${x.id === d.lesson ? 'selected' : ''} ${liveActs(x).length ? '' : 'disabled'}>${esc(x.title)} — ${liveActs(x).length ? (n ? `đã có ${n} buổi` : 'chưa có buổi') : 'không có hoạt động trực tiếp'}</option>`; }).join('')}</select>${errOf(e, 'lesson', N.tried)}</div><div class="ui-field"><label class="ui-field__label" for="f-act"><span class="ui-field__required">*</span>Hoạt động học trực tiếp</label><select id="f-act" data-f="activity" ${l ? '' : 'disabled'}>${l ? liveActs(l).map(a => `<option value="${a.id}" ${a.id === d.activity ? 'selected' : ''}>${esc(a.name)}</option>`).join('') : '<option>Chọn bài học trước</option>'}</select></div></div>` +
        (existing.length ? `<div class="ses-note ses-note--warn"><i data-lucide="copy-plus" class="ui-icon" aria-hidden="true"></i><span><b>Bài đã có ${existing.length} buổi</b> (${existing.map(s => dmhm(D(s.start))).join(', ')}). Buổi thêm sẽ là buổi thứ ${existing.length + 1} của bài: dùng chung hoạt động Trước/Sau — Trước mở theo buổi đầu tiên, Sau theo buổi cuối cùng của bài. Điểm danh tính riêng từng buổi.</span></div>` : '') + `</section>` : '') +
      `<section><header><h4>Tên buổi học</h4></header><div class="ui-field"><label class="ui-field__label" for="f-title"><span class="ui-field__required">*</span>Tên buổi học</label><input id="f-title" data-f="title" value="${esc(d.title)}" maxlength="255" placeholder="${d.type === 'curriculum' ? 'Gợi ý từ bài học và hoạt động' : 'Ví dụ: Buổi phụ đạo phát âm'}" ${inv(e, 'title', N.tried)}>${errOf(e, 'title', N.tried)}<span class="ui-caption" style="font-size:12px">${d.type === 'curriculum' ? 'Gợi ý từ bài học đã chọn; có thể chỉnh sửa.' : 'Bắt buộc với buổi ngoài chương trình.'}</span></div></section>` +
      `<section><header><h4>Thời gian</h4>${N.occ ? '<span class="ui-caption">Theo ngày dự kiến của lịch học. Cần đổi giờ thì tạo xong rồi dời lịch.</span>' : '<span class="ui-caption">Buổi ngoài lịch học.</span>'}</header>${timeFields(d, e, N.tried, !!N.occ, N.occ ? 'Nguồn: lịch chính tháng 11 · lưu nguồn bất biến.' : '')}${N.occ ? errOf(e, 'time', N.tried) : ''}</section>` +
      `<section><header><h4>Hình thức</h4></header>${deliveryFields(d, e, N.tried, false)}</section>` +
      `<section><header><h4>Giáo viên</h4><span class="ui-caption">Chỉ chọn được người rảnh trong toàn bộ khung giờ.</span></header>${teacherFields(d, e, N.tried, false)}</section></div>` +
      `<aside class="ses-side"><div class="ses-side__head"><div><h4>Nội dung buổi học</h4><span class="ui-caption">Từ template phiên bản đã khóa · chỉ xem.</span></div></div>` +
      (d.type === 'operational' ? `<div class="ses-note"><i data-lucide="info" class="ui-icon" aria-hidden="true"></i><span>Buổi ngoài chương trình không gắn bài học, không mở hoạt động Trước/Trong/Sau và không ảnh hưởng tiến độ.</span></div>`
        : !l ? '<p class="ses-phase__empty">Chọn bài học để xem nội dung Trước / Trong / Sau.</p>'
        : hyp ? phasesHtml(l, windows(hyp, hyp[hyp.length - 1])) + `<p class="ui-caption" style="font-size:12px">Mốc mở/hạn tính theo giờ buổi và buổi liền kề — quy tắc đề xuất, chờ Owner duyệt.</p>`
        : phasesHtml(l, {before:{}, during:{}, after:{}}, true).replace(/<div class="ses-phase__win">[\s\S]*?<\/div><div class="ses-phase__rule">[\s\S]*?<\/div>/g, '<p class="ses-phase__rule">Chọn thời gian hợp lệ để tính mốc.</p>')) + `</aside></div>`;
    const errs = Object.keys(e).length;
    const note = $('ses-add-note'); note.className = 'ses-footer-note' + (N.tried && errs ? ' is-error' : '');
    note.textContent = N.tried && errs ? `Còn ${errs} mục cần sửa.` : (d.teachers.length ? '' : 'Chưa phân công giáo viên.');
    icons();
  }

  // ---------- batch dialog ----------
  let B = null;
  function openBatch(){
    const rows = OCC.filter(o => !S.some(s => s.origin === o.id) && D(o.start) > NOW).map(o => ({o, sel:false, lesson:null, mode:'online', teacher:null}));
    B = {rows, tried:false, room:'', address:''}; renderBatch(); $('ses-batch').showModal();
  }
  function rowState(r){
    if (!r.sel) return ['ses-badge--muted', 'Chưa chọn', false];
    if (!r.lesson) return ['ses-badge--warn', 'Thiếu bài học', true];
    if (r.teacher && busy(teacher(r.teacher), r.o.start, r.o.end)) return ['ses-badge--danger', 'Trùng lịch GV', true];
    if (r.mode !== 'online' && (!B.room || !B.address)) return ['ses-badge--warn', 'Thiếu phòng học', true];
    return ['ses-badge--success', r.teacher ? 'Sẵn sàng' : 'Sẵn sàng · chưa có GV', false];
  }
  function renderBatch(){
    const withLive = LESSONS.filter(l => liveActs(l).length);
    const planned = new Set(S.filter(s => active(s) && s.lesson).map(s => s.lesson).concat(B.rows.filter(r => r.sel && r.lesson).map(r => r.lesson)));
    const sel = B.rows.filter(r => r.sel), bad = sel.filter(r => rowState(r)[2]), needPlace = sel.some(r => r.mode !== 'online');
    $('ses-batch-body').innerHTML = `<div class="ses-batch-top"><div><strong>${[...planned].filter(id => withLive.some(l => l.id === id)).length}/${withLive.length} bài đã có buổi</strong><span class="ui-caption" style="display:block;font-size:12px">tính cả các dòng đang chọn · còn ${withLive.length - [...planned].length} bài chưa xếp</span><div class="ses-bar" style="width:260px"><span style="width:${Math.round([...planned].length / withLive.length * 100)}%"></span></div></div>` +
      `<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end"><div class="ui-field"><label class="ui-field__label" for="b-sch">Lịch học</label><select id="b-sch"><option>lịch chính tháng 11 · 01/11 → 30/11</option></select></div><button type="button" class="ui-button ui-button--outline" id="b-auto"><i data-lucide="sparkles" class="ui-icon" aria-hidden="true"></i>Tự xếp theo thứ tự chương trình</button></div></div>` +
      `<p class="ui-caption" style="font-size:12px;margin:8px 0 12px">“Tự xếp” gán lần lượt các bài chưa có buổi vào các dòng đang chọn theo thứ tự thời gian; có thể đổi từng dòng. Bài không có hoạt động trực tiếp được bỏ qua.</p>` +
      (needPlace ? `<div class="ses-grid" style="margin-bottom:12px"><div class="ui-field"><label class="ui-field__label" for="b-room"><span class="ui-field__required">*</span>Phòng học (cho các buổi offline/hybrid)</label><input id="b-room" data-b="room" value="${esc(B.room)}" placeholder="Ví dụ: Phòng 201"></div><div class="ui-field"><label class="ui-field__label" for="b-addr"><span class="ui-field__required">*</span>Địa chỉ</label><input id="b-addr" data-b="address" value="${esc(B.address)}" placeholder="Nhập địa chỉ"></div></div>` : '') +
      `<div class="mk-table-scroll"><table class="ses-btable"><thead><tr><th scope="col" class="ses-col-check"><input type="checkbox" id="b-all" aria-label="Chọn tất cả ngày dự kiến" ${sel.length === B.rows.length && B.rows.length ? 'checked' : ''}></th><th scope="col">Ngày và giờ</th><th scope="col">Bài học</th><th scope="col">Nội dung</th><th scope="col">Hình thức</th><th scope="col">Giáo viên</th><th scope="col">Trạng thái</th></tr></thead><tbody>` +
      B.rows.map((r, i) => { const s0 = D(r.o.start), st = rowState(r), l = lesson(r.lesson); return `<tr class="${r.sel ? (st[2] && B.tried ? 'is-err' : '') : 'is-off'}"><td class="ses-col-check"><input type="checkbox" data-bsel="${i}" ${r.sel ? 'checked' : ''} aria-label="Chọn ${dmy(s0)} ${hm(s0)}"></td><td><strong>${dmy(s0)} · ${hm(s0)}–${hm(D(r.o.end))}</strong><span class="ses-sub">${WD[s0.getDay()]}</span></td>` +
        `<td><select data-bl="${i}" ${r.sel ? '' : 'disabled'} aria-label="Bài học cho ${dmy(s0)}"><option value="">Chọn bài học</option>${withLive.map(x => `<option value="${x.id}" ${x.id === r.lesson ? 'selected' : ''}>${esc(x.title.split(':')[0])} · ${esc(x.title.split(': ')[1])}${planned.has(x.id) && x.id !== r.lesson ? ' (đã có buổi)' : ''}</option>`).join('')}</select></td>` +
        `<td>${l ? phaseChips(l) : '<span class="ses-sub">—</span>'}</td><td><select data-bm="${i}" ${r.sel ? '' : 'disabled'} aria-label="Hình thức cho ${dmy(s0)}">${Object.entries(MODE).map(([k, v]) => `<option value="${k}" ${r.mode === k ? 'selected' : ''}>${v}</option>`).join('')}</select></td>` +
        `<td><select data-bt="${i}" ${r.sel ? '' : 'disabled'} aria-label="Giáo viên cho ${dmy(s0)}"><option value="">Chưa phân công</option>${TEACHERS.map(t => { const b = busy(t, r.o.start, r.o.end); return `<option value="${t.id}" ${t.id === r.teacher ? 'selected' : ''}>${esc(t.name)}${b ? ' — bận' : ''}</option>`; }).join('')}</select></td><td><span class="ses-badge ${st[0]}">${st[1]}</span></td></tr>`; }).join('') + `</tbody></table></div>`;
    const note = $('ses-batch-note'); note.className = 'ses-footer-note' + (bad.length ? ' is-error' : '');
    note.textContent = `${sel.length - bad.length} buổi sẵn sàng tạo${bad.length ? ` · ${bad.length} dòng cần xử lý` : ''} · ${B.rows.length - sel.length} ngày chưa chọn`;
    $('ses-batch-save').disabled = !sel.length || bad.length > 0;
    $('ses-batch-save').querySelector('span').textContent = `Tạo ${sel.length || ''} buổi học`.replace('  ', ' ');
    icons();
  }

  // ---------- events ----------
  function toast(m){ const t = $('ses-toast'); t.textContent = m; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => t.hidden = true, 3200); }
  root.addEventListener('click', e => {
    const t = e.target.closest('button');
    if (!t) return;
    if (t.dataset.view) { view = t.dataset.view; render(); }
    else if (t.dataset.filter) { filter = t.dataset.filter; render(); }
    else if (t.dataset.expand) { openRow = openRow === +t.dataset.expand ? null : +t.dataset.expand; render(); }
    else if (t.dataset.edit) openEdit(t.dataset.edit);
    else if (t.dataset.addOcc) openAdd({occ:t.dataset.addOcc});
    else if (t.dataset.addLesson) openAdd({lesson:t.dataset.addLesson});
    else if (t.hasAttribute('data-open-add')) openAdd();
    else if (t.hasAttribute('data-open-batch')) openBatch();
    else if (t.hasAttribute('data-dlg-cancel')) t.closest('dialog').close();
    else if (t.id === 'b-auto') { const free = LESSONS.filter(l => liveActs(l).length && !S.some(s => s.lesson === l.id && active(s))); let k = 0; if (!B.rows.some(r => r.sel)) B.rows.slice(0, free.length).forEach(r => r.sel = true); B.rows.filter(r => r.sel).forEach(r => { r.lesson = free[k] ? free[k++].id : r.lesson; }); renderBatch(); }
    else if (t.dataset.demo === 'reset') { S = clone(SEED); nextId = 8; openRow = null; render(); }
    else if (t.dataset.demo === 'edit') { openEdit(6); E.d.t1 = '18:00'; E.d.t2 = '20:00'; E.d.date = '2026-11-09'; renderEdit(); }
    else if (t.dataset.demo === 'extra') openAdd({lesson:3});
  });
  root.querySelectorAll('dialog').forEach(dl => dl.addEventListener('click', e => { if (e.target === dl) dl.close(); }));
  // Clear a closed popup so its field ids never duplicate the ids of another open popup.
  ['ses-edit', 'ses-add', 'ses-batch'].forEach(id => $(id).addEventListener('close', () => { $(id + '-body').innerHTML = ''; }));
  const bindForm = (dlgId, get, rerender, isEdit) => {
    const dl = $(dlgId);
    dl.addEventListener('change', e => {
      const t = e.target, st = get(); if (!st) return;
      if (t.dataset.f) {
        const k = t.dataset.f; let v = t.value;
        if (k === 'lesson') { v = v ? +v : null; st.d.lesson = v; st.d.activity = liveActs(lesson(v))[0]?.id || null; if (!isEdit) suggestTitle(); }
        else if (k === 'activity') { st.d.activity = +v; if (!isEdit) suggestTitle(); }
        else if (k === 'type') { st.d.type = v; if (v === 'operational') { st.d.lesson = null; st.d.activity = null; N.titleDirty = false; st.d.title = ''; } else suggestTitle(); }
        else st.d[k] = v;
        rerender();
      } else if (t.dataset.teacher) { const id = +t.dataset.teacher; st.d.teachers = t.checked ? [...st.d.teachers, id] : st.d.teachers.filter(x => x !== id); rerender(); }
    });
    dl.addEventListener('input', e => { const t = e.target, st = get(); if (st && t.dataset.f && ['title', 'link', 'room', 'address', 'reason'].includes(t.dataset.f)) { st.d[t.dataset.f] = t.value; if (t.dataset.f === 'title' && !isEdit) N.titleDirty = true; } });
  };
  bindForm('ses-edit', () => E, () => renderEdit(), true);
  bindForm('ses-add', () => N, () => renderAdd(), false);
  $('ses-edit-form').addEventListener('submit', ev => {
    ev.preventDefault(); E.tried = true;
    const d = E.d, e = check(d, E.s.id);
    if (Object.keys(e).length) { renderEdit(); return; }
    const s = E.s, st = at(d.date, d.t1), en = at(d.date, d.t2), moved = st !== s.start || en !== s.end;
    if (moved) s.history.push({at:'2026-11-05T10:00', by:'Visang 1', from:[s.start, s.end], to:[st, en], reason:d.reason.trim()});
    Object.assign(s, {lesson:d.lesson, activity:d.activity, title:d.title.trim(), start:st, end:en, mode:d.mode, link:d.link.trim(), room:d.room.trim(), address:d.address.trim(), teachers:d.teachers});
    $('ses-edit').close(); render(); toast(moved ? 'Đã lưu và dời lịch buổi học. Mốc Trước/Sau của các buổi liền kề được tính lại.' : 'Đã cập nhật buổi học.');
  });
  $('ses-add-form').addEventListener('submit', ev => {
    ev.preventDefault(); N.tried = true;
    const d = N.d, e = check(d, null);
    if (Object.keys(e).length) { renderAdd(); return; }
    S.push({id:nextId++, type:d.type, lesson:d.lesson, activity:d.activity, title:d.title.trim(), start:at(d.date, d.t1), end:at(d.date, d.t2), origin:N.occ ? N.occ.id : null, status:'scheduled', mode:d.mode, link:d.link.trim(), room:d.room.trim(), address:d.address.trim(), teachers:d.teachers, history:[]});
    $('ses-add').close(); render(); toast('Đã tạo buổi học.');
  });
  $('ses-batch').addEventListener('change', e => {
    const t = e.target;
    if (t.id === 'b-all') B.rows.forEach(r => r.sel = t.checked);
    else if (t.dataset.bsel) B.rows[+t.dataset.bsel].sel = t.checked;
    else if (t.dataset.bl) B.rows[+t.dataset.bl].lesson = t.value ? +t.value : null;
    else if (t.dataset.bm) B.rows[+t.dataset.bm].mode = t.value;
    else if (t.dataset.bt) B.rows[+t.dataset.bt].teacher = t.value ? +t.value : null;
    else if (t.dataset.b) B[t.dataset.b] = t.value;
    renderBatch();
  });
  $('ses-batch').addEventListener('input', e => { const t = e.target; if (t.dataset.b) { B[t.dataset.b] = t.value; } });
  $('ses-batch-form').addEventListener('submit', ev => {
    ev.preventDefault(); B.tried = true;
    const sel = B.rows.filter(r => r.sel); if (!sel.length || sel.some(r => rowState(r)[2])) { renderBatch(); return; }
    sel.forEach(r => { const l = lesson(r.lesson), a = liveActs(l)[0]; S.push({id:nextId++, type:'curriculum', lesson:l.id, activity:a.id, title:`${l.title.split(':')[0]} · ${a.name}`, start:r.o.start, end:r.o.end, origin:r.o.id, status:'scheduled', mode:r.mode, link:'', room:r.mode === 'online' ? '' : B.room, address:r.mode === 'online' ? '' : B.address, teachers:r.teacher ? [r.teacher] : [], history:[]}); });
    $('ses-batch').close(); render(); toast(`Đã tạo ${sel.length} buổi học từ lịch.`);
  });
  $('ses-search').addEventListener('input', e => { query = e.target.value.trim(); render(); });

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

  openRow = 6;
  render();
})();
