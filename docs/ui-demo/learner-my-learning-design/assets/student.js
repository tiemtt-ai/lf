/* Học viên — Góc học tập của tôi. Dữ liệu minh họa + tương tác của bản thiết kế tĩnh.
   Markup của thẻ chép từ mk_v2: student-course-card, student-exam-card, standard-live-courses. */
(() => {
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const NOW = new Date('2026-11-10T20:00:00+07:00').getTime();
  const lock = '<svg class="student-exam-lock" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg>';
  const heart = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg>';
  const examIcon = '<svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v14h16V6a2 2 0 0 0-2-2h-3"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="m8 12 2 2 6-6M8 17h8"/></svg>';
  const resultIcon = '<svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3v17h17M8 16v-4m5 4V8m5 8V5"/></svg>';

  const PAGES = {
    mk: {title:'Khoá học của tôi', help:'Quản lý các khóa học tự học hiện tại, đã hoàn thành và được bạn quan tâm.',
      tabs:{current:'Khoá học hiện tại', completed:'Khoá học hoàn thành', favorite:'Khoá học quan tâm'}, search:'Tên khóa học / lớp học', found:'khóa học / lớp học'},
    mklive: {title:'Lớp học của tôi', help:'Quản lý các lớp học trực tiếp hiện tại, đã hoàn thành và được bạn quan tâm.',
      tabs:{current:'Lớp học hiện tại', completed:'Lớp học hoàn thành', favorite:'Lớp học quan tâm'}, search:'Tên khóa học / lớp học', found:'khóa học / lớp học'},
    exam: {title:'Bài thi của tôi', help:'Quản lý các bài thi còn lượt, đã hết lượt và được bạn quan tâm.',
      tabs:{current:'Bài thi hiện tại', completed:'Bài thi hết lượt', favorite:'Bài thi quan tâm'}, search:'Tên bài thi', found:'bài thi'},
    results: {title:'Kết quả học tập', help:'Theo dõi tiến độ, điểm danh và kết quả kiểm tra từ các nội dung được trung tâm cấp.', tabs:{}},
  };

  // Khoá học tự học (LF Enrollment self_paced). days = số ngày học, starts/ends = hạn truy cập.
  const courses = [
    {id:1, tab:'current', cat:'Sơ cấp', title:'Tiếng Hàn Sơ cấp 1 – Tự học', teacher:'Teacher 1', lessons:5, days:120, starts:'05/11/2026', ends:'05/03/2027', state:'days', left:115, progress:20, liked:false, img:true},
    {id:2, tab:'current', cat:'TOPIK', title:'Luyện nghe TOPIK I – Tự học', teacher:'Teacher 1', lessons:4, days:60, starts:'01/09/2026', ends:'30/11/2026', state:'review', left:20, progress:75, liked:true, img:true},
    {id:3, tab:'current', cat:'Sơ cấp', title:'Tiếng Hàn Sơ cấp 2 (100 Bài Giảng)', teacher:'Ninh Thị Thúy', lessons:100, days:180, starts:'15/11/2026', ends:'15/05/2027', state:'upcoming', left:186, progress:0, liked:false, img:true, upcoming:true},
    {id:4, tab:'completed', cat:'Sơ cấp', title:'Bảng chữ cái Hangul cấp tốc', teacher:'Teacher 2', lessons:6, days:30, starts:'01/08/2026', ends:'30/09/2026', state:'expired', left:0, progress:100, liked:false, img:true},
    {id:5, tab:'completed', cat:'Trung cấp', title:'Ngữ pháp trung cấp tổng hợp', teacher:'Teacher 1', lessons:15, days:90, starts:'01/03/2026', ends:'31/07/2026', state:'expired', left:0, progress:60, liked:true, img:true},
  ];
  // Lớp học (LF Cohort seat).
  const classes = [
    {id:11, tab:'current', cat:'Lớp học tiếng Hàn', mode:'Live online', title:'Lớp tiếng Hàn sơ cấp 1 – K12', teacher:'Teacher 1', days:'Thứ 3, Thứ 5, Thứ 7', time:'19:30 ~ 21:00', code:'HQ-SC1-K12', period:'01/11/2026 ~ 30/11/2026', held:3, total:12, minutes:90, progress:25, letter:'A', liked:false, ask:true},
    {id:12, tab:'current', cat:'Luyện thi TOPIK', mode:'Hybrid', title:'Lớp luyện thi TOPIK II – Cuối tuần', teacher:'Teacher 2', days:'Thứ 7, Chủ nhật', time:'08:30 ~ 10:30', code:'TP2-WK-03', period:'15/11/2026 ~ 15/01/2027', held:0, total:20, minutes:120, progress:0, letter:'T', liked:true, ask:false, upcoming:true},
    {id:13, tab:'completed', cat:'Lớp học tiếng Hàn', mode:'Offline', title:'Lớp giao tiếp cơ bản – K09', teacher:'Teacher 1', days:'Thứ 2, Thứ 4', time:'18:00 ~ 19:30', code:'GT-CB-K09', period:'01/07/2026 ~ 30/09/2026', held:11, total:12, minutes:90, progress:92, letter:'G', liked:false, ask:false},
  ];
  // Bài thi (LF Quiz registration). schedule = khung giờ vào thi (chưa có trong LF, xem README Q4).
  const exams = [
    {id:21, tab:'current', title:'중급1 테스트 <80강>', minutes:45, attempts:0, limit:100, results:0, liked:false, schedule:['2026-11-08T08:00:00+07:00','2026-11-08T10:00:00+07:00']},
    {id:22, tab:'current', title:'중급1 테스트 <71강>', minutes:45, attempts:0, limit:100, results:0, liked:false, schedule:['2026-11-10T19:30:00+07:00','2026-11-10T21:00:00+07:00']},
    {id:23, tab:'current', title:'TOPIK II 18회 읽기', minutes:70, attempts:4, limit:100, results:4, liked:true},
    {id:24, tab:'current', title:'TOPIK II 17회 읽기', minutes:70, attempts:0, limit:100, results:0, liked:false},
    {id:25, tab:'completed', title:'TOPIK I 90회 듣기', minutes:40, attempts:2, limit:2, results:2, liked:false},
  ];
  const DATA = {mk:courses, mklive:classes, exam:exams, results:[]};

  let page = 'mk', tab = 'current', q = '', cat = '';
  const inTab = (it, t) => t === 'favorite' ? it.liked : it.tab === t;
  const matches = it => (!q || it.title.toLowerCase().includes(q)) && (!cat || (it.cat || '').includes(cat) || it.title.includes(cat));
  const likeBtn = it => `<button type="button" class="student-products__like ${it.liked ? 'is-liked' : ''}" data-like="${it.id}" aria-pressed="${it.liked}" aria-label="Quan tâm / bỏ quan tâm sản phẩm">${heart}</button>`;

  // ---- Khoá học: b2b/profile/student-course-card ----
  const courseCard = c => {
    const direct = tab === 'current' && c.tab === 'current';
    const status = c.tab === 'completed' ? ['sb2b-course-expired', 'Hết thời hạn sử dụng'] : c.upcoming ? ['sb2b-course-enrolled', 'Chưa bắt đầu'] : ['sb2b-course-enrolled', 'Đang sử dụng'];
    const access = {days:`Còn ${c.left} ngày`, review:`Đang ôn tập · Còn ${c.left} ngày`, upcoming:'Chưa bắt đầu', expired:'Đã hết hạn'}[c.state];
    const action = c.tab === 'completed' ? `<a class="sb2b-course-view sb2b-course-reregister" href="#"><span aria-hidden="true">↻</span> Đăng ký</a>`
      : `<a class="sb2b-course-view sb2b-learning-entry" href="#">Vào học <span aria-hidden="true">→</span></a>`;
    return `<article class="card hover-shadow sb2b-course-card student-course-card">
      <a href="#" class="area-img text-center"><img class="w-100" src="assets/img/default_course.png" alt="" loading="lazy"></a>
      <div class="card-body">
        <div>
          <div class="sb2b-course-badges"><span class="sb2b-course-category">${esc(c.cat)}</span><span class="${status[0]}">${status[1]}</span>${likeBtn(c)}</div>
          <a class="${direct ? 'student-course-card__primary-link' : ''}" href="#"><h5 class="card-title line-2">${esc(c.title)}</h5></a>
          <div class="sb2b-live-card__teacher"><span class="sb2b-live-card__avatar">${esc(c.teacher.trim()[0].toUpperCase())}</span><span>${esc(c.teacher)}</span></div>
          <div class="sb2b-course-facts">
            <div class="sb2b-course-fact"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="m10 8 5 4-5 4z"/></svg><span>Bài giảng</span><strong>${c.lessons}</strong></div>
            <div class="sb2b-course-fact"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>Số ngày học</span><strong>${c.days}</strong></div>
            <div class="student-course-card__access ${c.state === 'expired' ? 'is-expired' : ''}">
              <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18"/></svg>
              <div class="student-course-card__access-content">
                <span class="student-course-card__access-label">Hạn truy cập</span>
                <p class="student-course-card__period"><time>${c.starts}</time><span aria-hidden="true">→</span><time>${c.ends}</time></p>
                <span class="student-course-card__access-status">${access}</span>
              </div>
            </div>
          </div>
        </div>
        <div class="card--state"><hr>
          <div class="student-course-card__actions"><p class="student-course-card__progress-text">Tiến độ: <strong>${c.progress}%</strong></p>${action}</div>
        </div>
      </div>
    </article>`;
  };

  // ---- Lớp học: b2b/profile/standard-live-courses ----
  const classCard = c => {
    const fav = tab === 'favorite';
    const info = tab === 'completed' ? `<b>Hoàn thành ${c.progress}%</b><span>Đã học ${c.held}/${c.total} buổi · ${c.minutes} phút/buổi</span>`
      : fav ? `<b>Đang theo dõi</b><span>${esc(c.period)}</span>`
      : c.upcoming ? `<b>Chưa bắt đầu</b><span>${c.total} bài</span>`
      : `<b>Tiến độ ${c.progress}%</b><span>Đã học ${c.held}/${c.total} buổi · ${c.minutes} phút/buổi</span>`;
    const main = tab === 'current'
      ? `<a href="#" class="btn-enter-class btn-live-class ${c.upcoming ? 'disabled' : ''}"><span class="btn-enter-icon"></span><span class="btn-enter-text">${c.upcoming ? 'Sắp diễn ra' : 'Vào lớp học'}</span></a>`
      : `<a href="#" class="btn-enter-class"><span class="btn-enter-icon">${tab === 'completed' ? '📚' : '👀'}</span><span class="btn-enter-text">${tab === 'completed' ? 'Xem lại khóa học' : 'Xem chi tiết'}</span></a>`;
    const second = fav ? `<form class="my-course-unfavorite-form"><button type="button" class="btn-ask-teacher" data-like="${c.id}"><span class="btn-chat-icon">♡</span><span>Bỏ quan tâm</span></button></form>`
      : c.ask ? `<a href="#" class="btn-ask-teacher"><span class="btn-chat-icon">💬</span><span>Tư vấn 1:1</span></a>` : '';
    return `<article class="my-course-card">
      <div class="my-course-thumb my-course-thumb--letter"><span>${esc(c.letter)}</span></div>
      <div class="my-course-content">
        <div class="my-course-tags"><span>${esc(c.cat)}</span><span>${esc(c.mode)}</span></div>
        <h3>${esc(c.title)}</h3>
        ${fav ? `<p>${esc(c.teacher)} · ${c.total} bài</p><p>Mã lớp: ${esc(c.code)}</p>`
          : `<p>${esc(c.teacher)} · ${esc(c.days)} · ${esc(c.time)}</p><p>Mã lớp: ${esc(c.code)} · Thời gian học: ${esc(c.period)}</p>`}
        <div class="my-progress-bar"><span style="width:${fav ? 0 : c.progress}%"></span></div>
        <div class="my-progress-info">${info}</div>
      </div>
      <div class="my-course-actions">${main}${second}</div>
    </article>`;
  };

  // ---- Bài thi: b2b/profile/student-exam-card ----
  const fmt = t => new Date(t).toLocaleTimeString('vi-VN', {hour:'2-digit', minute:'2-digit', timeZone:'Asia/Ho_Chi_Minh'});
  const examCard = e => {
    const done = e.tab === 'completed';
    let sched = '', openWindow = true;
    if (e.schedule) {
      const [s, en] = e.schedule.map(x => new Date(x).getTime());
      openWindow = NOW >= s && NOW <= en;
      const cls = NOW > en ? 'is-closed' : openWindow ? 'is-open' : '';
      const txt = NOW > en ? 'Đã hết giờ vào thi' : openWindow ? `Còn thời gian vào thi · đến ${fmt(en)}` : `Bắt đầu sau · ${fmt(s)}`;
      sched = `<p class="student-exam-schedule ${cls}"><strong class="student-exam-schedule__label">Bài thi theo lịch</strong><span class="student-exam-schedule__line"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 6v6l4 2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><span class="student-exam-schedule__status">${txt}</span></span></p>`;
    }
    const start = done ? `<button type="button" class="student-exam-actions__start" disabled>${e.schedule ? 'Vào thi' : 'Làm bài'} ${lock}</button>`
      : e.schedule ? `<a class="student-exam-actions__start" data-scheduled-exam aria-disabled="${!openWindow}" ${openWindow ? 'href="#"' : ''}>Vào thi <span class="student-exam-arrow" aria-hidden="true">→</span>${lock}</a>`
      : `<a class="student-exam-actions__start" href="#">Làm bài <span aria-hidden="true">→</span></a>`;
    const result = e.attempts > 0 && e.results > 0 ? `<a class="student-exam-actions__results" href="#"><span>Kết quả</span><span aria-hidden="true">→</span></a>`
      : `<button type="button" class="student-exam-actions__results" disabled>Kết quả ${lock}</button>`;
    return `<article class="student-products__card">
      <a href="#" class="student-products__image"><div class="student-exam-placeholder" aria-hidden="true">${examIcon}</div></a>
      <div class="student-products__body">
        ${likeBtn(e)}
        <div class="student-products__badge">${done ? 'Hết lượt thi' : 'Đang sử dụng'}</div>
        <h2><a href="#">${esc(e.title)}</a></h2>
        <p class="student-exam-facts"><span>Thời gian làm bài: <strong>${e.minutes} phút</strong></span><span>Số lượt đã thi: ${e.attempts} / ${e.limit}</span></p>
        ${sched}
        <div class="student-exam-actions student-exam-actions--pair">${start}${result}</div>
      </div>
    </article>`;
  };

  function render(){
    const P = PAGES[page], items = $('demo-empty').checked ? [] : DATA[page];
    document.title = `${P.title} - Học viên`;
    $('page').className = `student-products container student-products--${page}`;
    document.querySelectorAll('.student-products__categories a').forEach(a => {
      const on = a.dataset.goto === page; a.classList.toggle('is-active', on); on ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current');
    });
    $('page-title').textContent = P.title; $('page-help').textContent = P.help;
    $('live').hidden = !$('demo-live').checked || page === 'results';
    $('tabbar').hidden = page === 'results';
    $('search-label').textContent = P.search || '';
    $('tabs').innerHTML = Object.entries(P.tabs).map(([k, v]) => `<a href="#" data-tab="${k}" class="${k === tab ? 'is-active' : ''}" ${k === tab ? 'aria-current="page"' : ''}>${v} <span class="student-products__tab-count">${items.filter(it => inTab(it, k)).length}</span></a>`).join('');
    const filters = (q ? 1 : 0) + (cat ? 1 : 0);
    $('filter-label').textContent = filters ? `Lọc (${filters})` : 'Lọc';
    $('exam-filter-toggle').classList.toggle('is-active', filters > 0);
    const list = items.filter(it => inTab(it, tab) && matches(it));
    $('found').hidden = !filters; $('found').textContent = `Tìm thấy ${list.length} ${P.found || ''}`;
    if (page === 'results') {
      $('list').innerHTML = `<div class="student-products__results">${resultIcon}<p>Kết quả học tập sẽ có ở đợt sau (LF chưa làm).</p></div>`;
    } else if (!list.length) {
      const empty = `<div class="student-products__empty">${page === 'exam' ? examIcon : page === 'mklive' ? '<svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 21h8M12 16v5m-2-14 5 3-5 3z"/></svg>' : '<svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5.5c2.7-1.2 5.3-1.2 8 0v14c-2.7-1.2-5.3-1.2-8 0zM20 5.5c-2.7-1.2-5.3-1.2-8 0v14c2.7-1.2 5.3-1.2 8 0z"/></svg>'}<p>Chưa có sản phẩm trong mục này.</p></div>`;
      $('list').innerHTML = page === 'mklive' ? `<div class="my-course-list">${empty}</div>` : `<div class="student-products__grid">${empty}</div>`;
    } else if (page === 'mklive') {
      $('list').innerHTML = `<div class="my-course-list"><div class="course-group active">${list.map(classCard).join('')}</div></div>`;
    } else {
      $('list').innerHTML = `<div class="student-products__grid ${page === 'mk' ? 'student-products__grid--courses' : ''}">${list.map(page === 'mk' ? courseCard : examCard).join('')}</div>`;
    }
  }

  function go(p){
    page = p; tab = 'current'; q = ''; cat = ''; $('exam-search').value = ''; $('exam-category').value = '';
    $('exam-filter-panel').hidden = true; $('exam-filter-toggle').setAttribute('aria-expanded', 'false');
    if (location.hash !== '#' + p) history.replaceState(null, '', '#' + p);
    document.querySelectorAll('details[open]').forEach(d => d.open = false);
    render();
  }

  document.addEventListener('click', e => {
    const t = e.target.closest('[data-goto],[data-tab],[data-like]');
    if (!t) return;
    e.preventDefault();
    if (t.dataset.goto) { go(t.dataset.goto); window.scrollTo({top:0}); }
    else if (t.dataset.tab) { tab = t.dataset.tab; render(); }
    else if (t.dataset.like) {
      const it = DATA[page].find(x => String(x.id) === t.dataset.like); it.liked = !it.liked; render();
      $('student-products-feedback').textContent = it.liked ? 'Đã thêm vào mục quan tâm.' : 'Đã bỏ khỏi mục quan tâm.';
    }
  });

  // Header: details menus — chỉ mở một menu, bấm ngoài / Esc để đóng (như standard-nav).
  const menus = [...document.querySelectorAll('.sb2b-header details:not(.sb2b-mobile-resources)')];
  menus.forEach(m => m.addEventListener('toggle', () => { if (m.open) menus.forEach(o => { if (o !== m && !o.contains(m)) o.open = false; }); }));
  document.addEventListener('click', e => menus.forEach(m => { if (m.open && !m.contains(e.target)) m.open = false; }));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { menus.forEach(m => m.open = false); $('exam-filter-panel').hidden = true; $('exam-filter-toggle').setAttribute('aria-expanded', 'false'); } });

  // Bộ lọc (product-filter).
  const toggle = $('exam-filter-toggle'), panel = $('exam-filter-panel');
  toggle.addEventListener('click', e => { e.stopPropagation(); const open = panel.hidden; panel.hidden = !open; toggle.setAttribute('aria-expanded', String(open)); if (open) $('exam-search').focus(); });
  document.addEventListener('click', e => { if (!panel.hidden && !panel.contains(e.target) && e.target !== toggle) { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); } });
  panel.addEventListener('submit', e => { e.preventDefault(); q = $('exam-search').value.trim().toLowerCase(); cat = $('exam-category').value; panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); render(); });
  $('filter-clear').addEventListener('click', e => { e.preventDefault(); q = ''; cat = ''; $('exam-search').value = ''; $('exam-category').value = ''; render(); });

  $('demo-live').addEventListener('change', render);
  $('demo-empty').addEventListener('change', render);
  const top = $('to-top');
  window.addEventListener('scroll', () => top.classList.toggle('is-visible', window.scrollY > 300), {passive:true});
  top.addEventListener('click', () => window.scrollTo({top:0, behavior:'smooth'}));
  window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (PAGES[h] && h !== page) go(h); });

  const start = location.hash.slice(1);
  go(PAGES[start] ? start : 'mk');
})();
