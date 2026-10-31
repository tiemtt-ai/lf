/* Static design interactions for Course Template · Đầu ra & năng lực.
   In-memory demo data only (taken from the dev template on 2026-10-31). Not production code:
   when adopting, Blade renders rows/links and server routes handle attach/remove/change/detach. */
(() => {
  const root = document.getElementById('ui-catalog-soft');
  const $ = id => document.getElementById(id);
  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icons = () => { if (window.lucide?.createIcons) window.lucide.createIcons(); };
  const norm = s => String(s).toLocaleLowerCase('vi').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');

  const nodes = [
    {id:1, code:'KOR-HANGUL-01', name:'Đọc và viết Hangul cơ bản', desc:'Nhận diện, đọc và viết hệ chữ cái Hangul trong ngữ cảnh học tiếng Hàn sơ cấp.'},
    {id:2, code:'KOR-BEGINNER-01', name:'Giao tiếp tiếng Hàn sơ cấp trong tình huống hằng ngày', desc:'Nghe, nói và phản hồi các tình huống quen thuộc ở cấp sơ cấp.'},
    {id:3, code:'KOR-BEGINNER-02', name:'Đọc hiểu và viết tiếng Hàn sơ cấp', desc:'Đọc hiểu thông tin cơ bản và viết câu, đoạn ngắn phù hợp trình độ sơ cấp.'},
    {id:4, code:'KOR-INTERMEDIATE-01', name:'Giao tiếp tiếng Hàn trung cấp', desc:'Nghe, nói và diễn đạt ý kiến trong các tình huống học tập và đời sống mở rộng.'},
    {id:5, code:'KOR-INTERMEDIATE-02', name:'Đọc hiểu và viết tiếng Hàn trung cấp', desc:'Đọc hiểu văn bản thông dụng và viết nội dung có cấu trúc ở cấp trung cấp.'},
    {id:6, code:'KOR-TOPIK-01', name:'Vận dụng tiếng Hàn theo yêu cầu TOPIK', desc:'Vận dụng kỹ năng nghe, đọc và viết theo mục tiêu ôn luyện, đánh giá TOPIK.'},
    {id:7, code:'KOR-BUSINESS-01', name:'Giao tiếp tiếng Hàn trong môi trường doanh nghiệp', desc:'Giao tiếp chuyên nghiệp trong các tình huống công việc cơ bản với doanh nghiệp Hàn Quốc.'},
    {id:8, code:'KOR-EPS-01', name:'Vận dụng tiếng Hàn định hướng EPS-TOPIK', desc:'Sử dụng tiếng Hàn cho mục tiêu học tập, thi cử và công việc định hướng EPS-TOPIK.'}
  ];
  const nodeById = id => nodes.find(n => n.id === id);
  // Groups = section path (Chương › Mục › Nhóm). Lessons keep course order.
  const groups = [
    {id:'g1', path:['Chương 1','Mục 1'], lessons:[
      {id:101, title:'Bài 1: giới thiệu', activities:5, links:[]},
      {id:102, title:'Bài 2: Bài học giao tiếp cơ bản', activities:1, links:[{node:6},{node:4},{node:2}]},
      {id:103, title:'Bài 3: Thực hành', activities:1, links:[]},
      {id:104, title:'Bài 4: giao tiếp với người hàn', activities:1, links:[]}]},
    {id:'g2', path:['Chương 1','Mục 2','Nhóm 1'], lessons:[{id:105, title:'Phụ âm cuối Patchim', activities:1, links:[]}]},
    {id:'g3', path:['Chương 1','Mục 2','Nhóm 2'], lessons:[]},
    {id:'g4', path:['Chương 2','Mục 1'], lessons:[{id:106, title:'Nguyên âm và phụ âm', activities:1, links:[]}]}
  ];
  let orphans = [{node:3, meta:'Hoạt động: Luyện viết (đã xoá)'}];
  const allLessons = () => groups.flatMap(g => g.lessons);
  const versionLabel = {'5:10':'v3','5:8':'v2','5:7':'Lộ trình tiếng Hàn v1','4:6':'Phiên bản mẫu 1'};
  const frameworkLabel = {'5':'Chuẩn đầu ra & năng lực tiếng Hàn','4':'Demo: Năng lực số cơ bản'};
  let current = '5:7', state = 'selected', filter = 'all', collapsed = new Set(), pickerLesson = null, pendingRemove = null;

  function toast(msg){ $('ctl-toast-text').textContent = msg; $('ctl-toast').hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => $('ctl-toast').hidden = true, 3000); }

  function chip(link, lesson){
    const n = nodeById(link.node);
    const meta = link.meta ? `<span class="ctl-chip__meta">${esc(link.meta)}</span>` : '';
    return `<li class="ctl-chip" title="${esc(n.desc)}"><span class="ctl-chip__code">${esc(n.code)}</span><span><span class="ctl-chip__name">${esc(n.name)}</span>${meta}</span>`+
      `<button type="button" class="ctl-chip__remove" data-remove="${lesson ? lesson.id : 'orphan'}" data-node="${n.id}" aria-label="Gỡ ${esc(n.name)}"><i data-lucide="x" class="ui-icon" aria-hidden="true"></i></button></li>`;
  }

  function render(){
    const q = norm($('ctl-lesson-search').value.trim());
    const lessons = allLessons();
    const mapped = lessons.filter(l => l.links.length).length;
    const counts = {all:lessons.length, mapped, unmapped:lessons.length - mapped};
    root.querySelectorAll('[data-count]').forEach(el => el.textContent = counts[el.dataset.count]);
    const links = lessons.reduce((s, l) => s + l.links.length, 0);
    const usedNodes = new Set(lessons.flatMap(l => l.links.map(k => k.node))).size;
    $('ctl-stat-lessons').innerHTML = `${mapped} <small>/ ${lessons.length}</small>`;
    $('ctl-bar-lessons').style.width = (lessons.length ? Math.round(mapped / lessons.length * 100) : 0) + '%';
    $('ctl-stat-nodes').innerHTML = `${usedNodes} <small>/ ${nodes.length}</small>`;
    $('ctl-bar-nodes').style.width = Math.round(usedNodes / nodes.length * 100) + '%';
    $('ctl-stat-links').textContent = links;
    $('ctl-change-links').textContent = links; $('ctl-detach-links').textContent = links;

    let html = '', no = 0, shown = 0;
    groups.forEach(g => {
      const visible = g.lessons.map(l => ({l, n: ++no})).filter(({l}) =>
        (filter === 'all' || (filter === 'mapped') === (l.links.length > 0)) &&
        (!q || norm(l.title + ' ' + l.links.map(k => nodeById(k.node).name + ' ' + nodeById(k.node).code).join(' ')).includes(q)));
      if (!visible.length && (filter !== 'all' || q)) return;
      const open = !collapsed.has(g.id);
      const done = g.lessons.filter(l => l.links.length).length;
      const path = g.path.map((p, i) => i < g.path.length - 1 ? `<span class="ctl-group__parent">${esc(p)}</span><span class="ctl-group__sep" aria-hidden="true">›</span>` : `<span>${esc(p)}</span>`).join('');
      html += `<tr class="ctl-group"><td colspan="4"><button type="button" class="ctl-group__toggle" aria-expanded="${open}" data-group="${g.id}"><i data-lucide="chevron-down" class="ui-icon" aria-hidden="true"></i><span class="ctl-group__path">${path}</span><span class="ctl-group__meta">${g.lessons.length ? `${g.lessons.length} bài · ${done} đã gắn` : 'Chưa có bài học'}</span></button></td></tr>`;
      if (!open) return;
      if (!g.lessons.length) { html += `<tr class="ctl-empty-row"><td colspan="4">Nhóm này chưa có bài học.</td></tr>`; return; }
      visible.forEach(({l, n}) => {
        shown++;
        html += `<tr class="ctl-lesson"><td class="ctl-col-no">${n}</td><td class="ctl-col-lesson"><span class="ctl-lesson__title">${esc(l.title)}</span><span class="ctl-lesson__meta">${l.activities} hoạt động</span></td>`+
          `<td class="ctl-col-skills">${l.links.length ? `<ul class="ctl-chips">${l.links.map(k => chip(k, l)).join('')}</ul>` : '<span class="ctl-unmapped">Chưa gắn năng lực</span>'}</td>`+
          `<td class="ctl-col-action"><button type="button" class="ui-button ui-button--outline ui-button--sm" data-attach="${l.id}" aria-label="Gắn năng lực cho ${esc(l.title)}"><i data-lucide="plus" class="ui-icon" aria-hidden="true"></i>Gắn năng lực</button></td></tr>`;
      });
    });
    if (!html) html = `<tr><td colspan="4" class="ctl-no-result">Không có bài học phù hợp.</td></tr>`;
    $('ctl-rows').innerHTML = html;
    $('ctl-result-count').textContent = `Hiển thị ${shown} / ${lessons.length} bài học`;
    $('ctl-orphan-list').innerHTML = orphans.map(k => chip(k, null)).join('');
    $('ctl-orphan-count').textContent = orphans.length;
    if (state === 'orphans' && !orphans.length) setState('selected');
    renderPreview();
    icons();
  }

  function renderPreview(){
    const q = norm($('ctl-preview-search').value.trim());
    const lessons = allLessons();
    $('ctl-preview-rows').innerHTML = nodes.filter(n => !q || norm(n.name + ' ' + n.code + ' ' + n.desc).includes(q)).map(n => {
      const c = lessons.filter(l => l.links.some(k => k.node === n.id)).length;
      return `<tr><td><span class="ctl-chip__code">${esc(n.code)}</span></td><td><strong style="font-weight:500">${esc(n.name)}</strong><div class="ui-caption">${esc(n.desc)}</div></td><td>${c ? `<span class="ctl-badge">${c} bài</span>` : '<span class="ui-caption">—</span>'}</td></tr>`;
    }).join('') || '<tr><td colspan="3" class="ctl-no-result">Không tìm thấy năng lực phù hợp</td></tr>';
  }

  function setState(s){
    state = s;
    root.querySelectorAll('[data-demo-state]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.demoState === s)));
    root.querySelectorAll('[data-state-show]').forEach(el => el.hidden = !el.dataset.stateShow.split(' ').includes(s));
    if (s === 'orphans' && !orphans.length) orphans = [{node:3, meta:'Hoạt động: Luyện viết (đã xoá)'}];
    render();
  }

  // Picker
  function renderPicker(){
    const q = norm($('ctl-picker-search').value.trim());
    const have = new Set(pickerLesson.links.map(k => k.node));
    const checked = new Set([...$('ctl-picker-nodes').querySelectorAll('input:checked')].map(i => +i.value));
    const list = nodes.filter(n => !q || norm(n.name + ' ' + n.code + ' ' + n.desc).includes(q))
      .sort((a, b) => have.has(a.id) - have.has(b.id)); // not-yet-linked first
    $('ctl-picker-nodes').innerHTML = list.map(n => {
      const done = have.has(n.id);
      return `<label class="ctl-node"><input type="checkbox" name="learning_node_ids[]" value="${n.id}" ${done ? 'disabled checked' : (checked.has(n.id) ? 'checked' : '')}>`+
        `<span class="ctl-node__body"><span class="ctl-node__top"><span class="ctl-chip__code">${esc(n.code)}</span>${done ? '<span class="ctl-badge ctl-badge--success">Đã gắn</span>' : ''}</span><span class="ctl-node__name">${esc(n.name)}</span><span class="ctl-node__desc">${esc(n.desc)}</span></span></label>`;
    }).join('');
    $('ctl-picker-empty').hidden = list.length > 0;
    $('ctl-picker-available').textContent = `· còn ${nodes.length - have.size} năng lực chưa gắn`;
    pickerCount();
  }
  function pickerCount(){
    const n = $('ctl-picker-nodes').querySelectorAll('input:checked:not(:disabled)').length;
    $('ctl-picker-count').textContent = `Đã chọn ${n} năng lực`;
    $('ctl-picker-submit').disabled = n === 0;
  }
  function openPicker(id){
    pickerLesson = allLessons().find(l => l.id === id);
    $('ctl-picker-lesson').textContent = pickerLesson.title;
    $('ctl-picker-search').value = '';
    $('ctl-picker-nodes').innerHTML = '';
    renderPicker();
    $('ctl-picker-dialog').showModal();
    $('ctl-picker-search').focus();
  }

  // Events
  root.addEventListener('click', e => {
    const t = e.target.closest('button');
    if (!t) return;
    if (t.dataset.demoState) setState(t.dataset.demoState);
    else if (t.dataset.filter) { filter = t.dataset.filter; root.querySelectorAll('[data-filter]').forEach(b => b.setAttribute('aria-selected', String(b === t))); render(); }
    else if (t.dataset.group) { collapsed.has(t.dataset.group) ? collapsed.delete(t.dataset.group) : collapsed.add(t.dataset.group); render(); }
    else if (t.dataset.attach) openPicker(+t.dataset.attach);
    else if (t.dataset.remove) {
      const lesson = t.dataset.remove === 'orphan' ? null : allLessons().find(l => l.id === +t.dataset.remove);
      pendingRemove = {lesson, node:+t.dataset.node, trigger:t};
      $('ctl-remove-name').textContent = nodeById(+t.dataset.node).name;
      $('ctl-remove-lesson').textContent = lesson ? `bài học “${lesson.title}”` : 'danh sách liên kết lỗi';
      $('ctl-remove-dialog').showModal();
    }
    else if (t.dataset.open) { const d = $(t.dataset.open); if (t.dataset.open === 'ctl-change-dialog') { $('ctl-change-select').value = current; changeCheck(); } d.showModal(); }
    else if (t.hasAttribute('data-close')) t.closest('dialog').close();
  });
  root.querySelectorAll('dialog').forEach(d => d.addEventListener('click', e => { if (e.target === d) d.close(); }));
  $('ctl-toggle-groups').addEventListener('click', e => {
    const all = collapsed.size < groups.length;
    collapsed = all ? new Set(groups.map(g => g.id)) : new Set();
    e.currentTarget.setAttribute('aria-pressed', String(all));
    e.currentTarget.querySelector('span').textContent = all ? 'Mở rộng tất cả' : 'Thu gọn tất cả';
    render();
  });
  $('ctl-lesson-search').addEventListener('input', render);
  $('ctl-preview-search').addEventListener('input', renderPreview);
  $('ctl-picker-search').addEventListener('input', renderPicker);
  $('ctl-picker-nodes').addEventListener('change', pickerCount);
  $('ctl-picker-form').addEventListener('submit', e => {
    e.preventDefault();
    const ids = [...$('ctl-picker-nodes').querySelectorAll('input:checked:not(:disabled)')].map(i => +i.value);
    if (!ids.length) return;
    ids.forEach(id => pickerLesson.links.push({node:id}));
    $('ctl-picker-dialog').close();
    toast(`Đã gắn ${ids.length} năng lực cho “${pickerLesson.title}”.`);
    render();
  });
  $('ctl-remove-form').addEventListener('submit', e => {
    e.preventDefault();
    const {lesson, node} = pendingRemove;
    if (lesson) lesson.links = lesson.links.filter(k => k.node !== node); else orphans = orphans.filter(k => k.node !== node);
    $('ctl-remove-dialog').close();
    toast(`Đã gỡ “${nodeById(node).name}”.`);
    render();
  });
  function changeCheck(){
    const v = $('ctl-change-select').value, differs = v !== current;
    $('ctl-change-warning').hidden = !differs; $('ctl-change-submit').disabled = !differs;
  }
  $('ctl-change-select').addEventListener('change', changeCheck);
  function applyFramework(v){
    current = v;
    const [f] = v.split(':');
    $('ctl-framework-name').textContent = frameworkLabel[f]; $('ctl-preview-title').textContent = frameworkLabel[f];
    $('ctl-framework-version').textContent = versionLabel[v]; $('ctl-change-current').textContent = versionLabel[v];
    [...$('ctl-change-select').options].forEach(o => o.textContent = versionLabel[o.value] + (o.value === v ? ' · Đang dùng' : ''));
    allLessons().forEach(l => l.links = []); orphans = [];
  }
  $('ctl-change-form').addEventListener('submit', e => {
    e.preventDefault();
    applyFramework($('ctl-change-select').value);
    $('ctl-change-dialog').close();
    toast('Đã đổi bộ chuẩn. Hãy gắn lại năng lực cho các bài học.');
    setState('selected');
  });
  $('ctl-detach-form').addEventListener('submit', e => {
    e.preventDefault();
    allLessons().forEach(l => l.links = []); orphans = [];
    $('ctl-detach-dialog').close();
    toast('Đã gỡ bộ chuẩn khỏi bản nháp.');
    setState('none');
  });
  $('ctl-initial-form').addEventListener('submit', e => {
    e.preventDefault();
    const v = $('ctl-initial-select').value;
    if (!v) { $('ctl-initial-select').reportValidity(); return; }
    applyFramework(v);
    toast('Đã chọn bộ chuẩn. Bắt đầu gắn năng lực cho bài học.');
    setState('selected');
  });

  // Minimal shell: collapse sidebar on desktop, drawer on mobile (same behaviour as admin-layout-developer).
  const shell = $('admin-shell'), sidebar = $('admin-sidebar'), drawer = $('admin-drawer'), toggle = $('admin-menu-toggle'), closeBtn = $('admin-close');
  const mq = matchMedia('(max-width:680px)'); let shellCollapsed = false;
  const syncShell = () => { toggle.setAttribute('aria-label', mq.matches ? 'Mở menu' : shellCollapsed ? 'Mở rộng menu' : 'Thu gọn menu'); toggle.setAttribute('aria-expanded', String(mq.matches ? drawer.open : !shellCollapsed)); shell.classList.toggle('is-collapsed', shellCollapsed && !mq.matches); };
  toggle.addEventListener('click', () => { if (mq.matches) { drawer.append(sidebar); closeBtn.hidden = false; drawer.showModal(); } else shellCollapsed = !shellCollapsed; syncShell(); });
  closeBtn.addEventListener('click', () => drawer.close());
  drawer.addEventListener('close', () => { shell.prepend(sidebar); closeBtn.hidden = true; syncShell(); toggle.focus(); });
  drawer.addEventListener('click', e => { if (e.target === drawer) drawer.close(); });
  mq.addEventListener('change', () => { if (drawer.open) drawer.close(); syncShell(); });
  syncShell();

  setState('selected');
})();
