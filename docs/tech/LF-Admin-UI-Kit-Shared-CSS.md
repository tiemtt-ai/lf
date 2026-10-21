# LF Admin UI Kit Shared CSS

Version: 1.9

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-10-19

Document Path: tech/LF-Admin-UI-Kit-Shared-CSS.md

## Owner approval — 2026-10-19

Owner đã xác nhận thiết kế preview đúng yêu cầu và phê duyệt Shared CSS cùng
popup UI KIT 1/2/3 cột làm chuẩn dùng chung cho **Admin/Giáo viên**. Các item
trong popup dùng cùng Shared CSS với form; UI Kit Developer gốc tiếp tục là
visual reference, không chỉnh sửa bản gốc. Shared CSS là nguồn implementation
presentation duy nhất cho consumer opt-in.

Khi Owner yêu cầu **“áp dụng UI KIT”** hoặc **“áp dụng popup UI KIT [1/2/3 cột]
cho [chức năng]”**, dùng contract trong tài liệu này cho đúng chức năng được
chỉ định. Phê duyệt chuẩn thiết kế không đồng nghĩa phê duyệt rollout: **chưa
áp dụng vào các form hiện tại**, không tự migrate và không đổi nghiệp vụ,
validation, tenant/auth hay luồng lưu dữ liệu. Các điều chỉnh thiết kế tiếp theo
thực hiện theo yêu cầu Owner.

Phê duyệt visual của Owner không thay thế bằng chứng kiểm thử kỹ thuật.
Implementation Status vẫn là Partial: các kiểm tra native browser PC/tablet/mobile,
cuộn, đóng popup và trả focus chưa kiểm chứng được vẫn giữ trạng thái pending/
blocked trong phần verification bên dưới; không chuyển thành PASS từ xác nhận
thiết kế này.

## Contract and adoption

Owner chọn UI Kit Developer làm visual reference và quy định trigger
**“áp dụng UI KIT”** ngày 2026-10-19. Dùng Shared CSS này cho form
Admin/Teacher được chỉ định; không tự migrate các form khác. Public/student
không tự động thuộc scope. Business owner vẫn quyết định dữ liệu, validation,
permissions, lifecycle và payload.

Presentation source: `resources/css/admin/admin-ui-kit.css`.
Visual reference: [UI Kit gốc](../ui-demo/ui-kit-developer/README.md).
Entry được Vite build riêng, chỉ nạp tại consumer được chỉ định; không nạp toàn bộ backend layout.
Không có một thư viện UI Kit implementation khác trước task này: tìm kiếm
CSS/Blade hiện hành không có namespace ui-/ux-/mk- của kit. Các primitive
lf-/admin- hiện hành vẫn có consumer nên chưa xóa hoặc đổi visual của chúng.
Không thêm Blade Component hay framework mới.

## Usage

Load the asset only for the explicitly adopted consumer:

```blade
@vite('resources/css/admin/admin-ui-kit.css')
<div class="lf-admin-ui">
    <div class="ui-field">
        <label class="ui-field__label" for="organization-name">Tên tổ chức</label>
        <input id="organization-name" name="name" type="text"
               aria-describedby="organization-name-help">
        <span class="ui-caption" id="organization-name-help">Nhập tên đầy đủ.</span>
    </div>
    <button class="ui-button ui-button--primary" type="submit">Lưu</button>
</div>
```

The root is a class, not a required global ID: separate forms may have separate
roots. IDs/labels/names remain caller-owned and must be unique per instance.
Use `aria-invalid="true"`, native `disabled` and `readonly` for control states.
Root canvas spacing follows the reference; apply it to the form region rather
than the whole back-office shell. Do not nest opt-in roots. Avoid mixing
legacy lf-/admin- control classes onto the same item; those styles may have
competing specificity. Preserve existing shell via admin-layout.css.

Portable hooks replace demo-only ID dependency:

| Responsibility | Portable markup hook | Compatibility hook in demo |
| --- | --- | --- |
| Tag input | data-ui-tags on input | catalog-tags ID |
| Tag add button | data-ui-tag-add | catalog-tag-add ID |
| Additional modal fields | data-ui-modal-extra | modal-extra ID |

UI/UX/mk class names are retained from the source to avoid needless markup
translation. Input base rules intentionally style native inputs/selects/
textarea only inside .lf-admin-ui. Combobox/calendar/modal/upload/editor need
their full reference markup and an application adapter in the form adoption
task. CSS alone does not implement keyboard navigation, validation, upload
progress/cancellation or Livewire state. Do not copy the preview's fixed demo
IDs or mock business data to production.

## Item inventory

| Group | Main canonical classes / native markup |
| --- | --- |
| Label/helper/required | ui-field, ui-field__label, ui-field__required, ui-caption |
| Text/email/password/number/textarea/native select | native controls inside root; ui-input-group, ui-input-group__addon |
| Search/tag | ui-search, ui-tag-input__tags, ui-tag; portable tag hooks above |
| Searchable single/multiple select | ux-combo, ux-combo__control, ux-combo__list, ux-option, ux-chip, ux-clear |
| Checkbox/radio/switch | ui-check, ui-radio-group__options, ui-switch; native checked/indeterminate/role=switch |
| Date/range/calendar | ui-date-range, ux-date, ux-date__button, ux-calendar, ux-range-error |
| File/media upload | ui-upload, ui-media-upload__preview/file/filename/remove/error |
| Editor | ui-editor, ui-editor__toolbar/tool/content/footer |
| Buttons/icons | ui-button, ui-button--primary/outline, ui-icon |
| Tabs | ui-tabs, ui-tabs__tab; mk-tabs, mk-tab |
| Table/pagination | ui-table-wrap, ui-table, ui-pagination; mk-table-scroll, mk-pages, ux-row-switch, ux-row-edit |
| Popup | ui-modal, ui-modal__header/body/footer/grid, data-columns |

Only groups present in the source are included. No invented badge/loading
visuals. All reference media rules are preserved: 760/520/460px responsive,
pointer:coarse and reduced motion. The original late combobox rules constrain
its height to 36px even in coarse-pointer context: this source limitation is
not silently redesigned here. Physical touch and screen reader validation
remain part of each later component integration/adoption acceptance.

## Tokens and single source

The stylesheet owns --ui-primary, --ui-border, --ui-text, --ui-muted and
--ui-background, plus exact palette literals as --ui-color-<hex>. Palette
values are extracted without approximating/merging similar colors. All visual
color usages reference variables; token declarations hold original literals.
--ui-font-family, --ui-control-height (36px) and --ui-control-radius (5px)
expose shared base controls. Typography, spacing, borders, radii and shadows
otherwise retain the reference values/cascade. 39 declarations superseded by
later identical selectors were removed without moving surviving declarations.
Demo global rules are scoped; icon utility uses :where to avoid overriding
component icon hiding. !important rules from reference remain where removing
them could change compound-control appearance; not a license for module hacks.

Edit this one stylesheet for shared visual changes. Do not regenerate over
reviewed changes or edit docs/ui-demo as an implementation source. When visual
reference changes, audit the delta and rerun comparison before updating CSS.

## Preview and verification

`resources/ui/admin-ui-kit-preview.html` uses the actual shared stylesheet.
Its canvas-only CSS defines body margin/background, no item styles. It uses
reference demo JS/Lucide directly for demonstration only; no duplicated JS
runtime or production route. Preview is dev-only static HTML, never deploy
it as an authenticated business screen. The HTML retains reference markup to
make like-for-like visual comparison possible.

2026-10-19 browser comparison in the same IAB, Arial, device scale unchanged:
727 descendant elements compared for geometry and 21 computed presentation
properties at 1440x900, 768x1024 and 375x812: **zero differences** at each size.
Screenshots visually inspected. Invalid/readonly/disabled and focus state for
sample text input also match. This is evidence for sampled presentation, not
an exhaustive pixel-image regression or proof of production JS/accessibility.
Hover, real touch, screen readers, network upload states, VI/EN application
consumers and every open popover state are not yet fully verified. Popup and
table styles are carried from reference, not rollout of new application flows.

Audit classification: Existing-Feature Change; Initial/Final Audit Level
MEDIUM for isolated stylesheet/build entry. No business/tenant/auth change,
no existing layout/style entry modified. Regression gates recorded after
implementation below. The server was restricted to a disposable directory of
reference/preview assets, never the repository; removed after QA.

### Regression result — 2026-10-19

Final Verdict: **PASS WITH DOCUMENTED RISKS** for CSS preparation, not production
form rollout. Node suite: **158 PASS**, including three opt-in isolation/token/
asset-entry checks. Documentation suites: **20 PASS / 22 assertions**.

`npm run build` PASS, 66 modules, 7.58s; `php artisan docs:lint` and
`git diff --check` PASS. Backend/auth/student/app built asset hashes unchanged;
only a separate UI Kit CSS asset added. No PHP/Blade component/business code,
existing production layout or original UI Kit changed. Full PHP suite and
Pint not repeated because no PHP implementation changed. Browser style samples
are local QA evidence; the unverified items above remain explicit.

## Class inventory

```text
cursor-interaction
lf-admin-ui
lf-admin-ui--catalog
mk-count
mk-footnote
mk-na
mk-page
mk-pages
mk-search
mk-section
mk-section-head
mk-tab
mk-table-foot
mk-table-scroll
mk-tablebar
mk-tabs
mk-total-col
ui-action-row
ui-button
ui-button--outline
ui-button--primary
ui-caption
ui-check
ui-combobox
ui-combobox__option
ui-combobox__panel
ui-combobox__search
ui-combobox__trigger
ui-date-range
ui-editor
ui-editor__content
ui-editor__footer
ui-editor__tool
ui-editor__toolbar
ui-field
ui-field__code
ui-field__hint
ui-field__label
ui-field__required
ui-grid
ui-icon
ui-input-group
ui-input-group__addon
ui-media-upload__error
ui-media-upload__file
ui-media-upload__filename
ui-media-upload__preview
ui-media-upload__remove
ui-modal
ui-modal-demo-controls
ui-modal-stage
ui-modal__body
ui-modal__close
ui-modal__footer
ui-modal__form
ui-modal__grid
ui-modal__header
ui-pagination
ui-radio-group__options
ui-search
ui-section
ui-state-cycle
ui-switch
ui-table
ui-table-wrap
ui-tabs
ui-tabs__tab
ui-tag
ui-tag-input__tags
ui-upload
ux-action-cell
ux-calendar
ux-calendar__day
ux-calendar__footer
ux-calendar__grid
ux-calendar__header
ux-calendar__weekday
ux-check-cell
ux-chip
ux-clear
ux-combo
ux-combo__control
ux-combo__icon
ux-combo__list
ux-date
ux-date__button
ux-option
ux-range-error
ux-row-edit
ux-row-selected
ux-row-switch
```


## Shared popup contract — Owner instruction 2026-10-19

**“Áp dụng popup UI KIT 2 cột cho form thêm giáo viên”** means: adopt this
shared markup/presentation for that named function only, with all inner items
using the same Shared CSS. Without an explicit column count, select 1/2/3 from
the actual field groups and report that decision. Never infer rollout to other
forms, new business behavior or permission to modify the original UI Kit.

Copy one dialog from `resources/ui/admin-ui-kit-popup.html`, rename its IDs,
labels and field names per instance, and replace sample fields with real
function-owned fields. Keep `ui-modal`, `ui-modal__form/header/body/grid/footer`
and the common item classes. `data-columns` sets maximum columns and widths
480/800/1120px. Body content width <=760px reduces 3 to 2 columns; <=460px
reduces every variant to 1 column. Header/footer remain outside the scrolling
body. Do not add module-specific popup CSS or fixed field widths.

Load `resources/js/admin-ui-kit-dialog.js` only in adopted consumers. A root
`.lf-admin-ui[data-ui-dialog-scope]` owns its dialogs and openers. Buttons use
`data-ui-dialog-open="unique-dialog-id"`, matching `aria-controls`,
`aria-haspopup="dialog"`, `aria-expanded="false"`. Native `<dialog
class="ui-modal" data-ui-dialog data-columns="2" aria-labelledby="unique-title">`
is labelled by its own heading. Close/Cancel buttons are `type="button"` with
`data-ui-dialog-close`. Escape and focus containment use native showModal;
close restores focus to the connected opener in that scope. Adapter lookup
is scope-local and idempotent; `destroy()` removes listeners on unmount.
No save/network logic is owned by this adapter. Real forms retain their real
method/action, CSRF and existing validation. Sample `method="dialog"` is only
an offline demonstration: do not copy it as a business save handler.

New or remounted scopes can call exported `initUiKitDialogs(scope)`; call the
returned instance's destroy method before removing that scope. Root IDs must
remain unique even if scopes repeat. No backdrop-click discard is added;
Close, Cancel and native Escape are the explicit dismissal paths.

### Responsive correction versus original reference

The source's <=460px one-column selector had lower specificity than its
3-to-2-column selector, causing 3-column popup fields to remain squeezed into
two columns on mobile. Shared CSS now matches the data-columns attribute in
both rules, so the later one-column rule wins. This is the deliberate mobile
layout correction requested by Owner, not a change to reference files.
Desktop dimensions/colors/typography/spacing remain the original values.
The earlier zero-difference report describes the pre-correction baseline;
mobile popup geometry is now intentionally different to fix that defect.


### Popup follow-up verification — 2026-10-19

Initial/Final Audit Level MEDIUM (isolated opt-in CSS/JS, no application
consumers). Implementation completed for shared markup, scoped dialog adapter
and responsive specificity fix. Native browser visual acceptance is **BLOCKED**
for this follow-up: the browser origin approval gate timed out without an
approval decision on localhost8765. Initial sample page loaded and its three
openers were observed; subsequent open/close/mobile measurements were not
returned. This is not evidence of unsafe content and was not bypassed with
another browser automation mechanism. Previous default-control comparison is
not claimed as acceptance of this newly corrected popup.

Node suite now **163 PASS**, including five adapter tests for idempotency,
scope isolation, remounted dialogs, cleanup and stale close events. These use
a small event/element fixture, not a native browser dialog, and do not prove
browser focus containment or responsive column layout. Build PASS, 67 modules,
3.52s. docs:lint and diff whitespace check PASS. Existing backend/auth/student
bundle hashes remain unchanged. No production form, business code, original
UI Kit, route or Blade Component changed. Shared popup CSS remains the same
single stylesheet; adapter contains no visual styles or submission handler.
Real native 1/2/3-column desktop/mobile, Escape/focus return, long-body scroll,
combobox/calendar clipping and coarse-pointer input acceptance remain pending
browser access. No newly saved temporary test logs/screenshots were created.

## Course category list adoption — 2026-10-19

Owner chỉ định danh sách `/admin/course-categories` áp dụng UI KIT. View dùng
chung `course-categories/index.blade.php` phục vụ Admin/Teacher; hai vai trò
nhận cùng presentation, giữ nguyên controller và phạm vi truy cập của từng vai trò.
Form create/edit và các module khác chưa chuyển. Shared CSS được nạp qua section
Vite của riêng view này, không thêm vào backend layout hoặc sửa UI Kit gốc.

Classification: Existing-Feature Change. Initial/Final Audit Level: MEDIUM.
Rationale: Blade presentation, shared opt-in stylesheet, pagination markup;
không thay đổi route, query, dữ liệu, tenant/auth, validation hay mutation.
Audit Level Escalation: None. Documents reviewed: README, LF-INDEX routing,
Architecture Guardrails, Regression Audit, Tech CSS, Admin Form Design Standard
và tài liệu này. Source of truth: UI Kit Developer và Shared CSS được Owner duyệt.

Shared `ui-list-*` classes bổ sung layout toolbar/filter, text cells và empty
state; không thêm CSS theo module, Blade Component hoặc JavaScript. Bộ lọc giữ
GET keyword/status; pagination dùng URL paginator giữ query string. Bảng cuộn
ngang trên mobile, filter 2 cột ở tablet và 1 cột ở mobile. Manage vẫn dẫn tới
route edit hiện có, bỏ menu trung gian khi chỉ có một action. Trạng thái/nổi bật
giữ nội dung thật, dùng caption thay badge chưa có mẫu duyệt.

Files changed: shared stylesheet, category index view, CategoryManagementTest
và tài liệu này. No new files. Tests cập nhật selector presentation, giữ kiểm
tra tenant isolation, teacher ownership, pagination, empty/filter state và CRUD.
Native visual QA PC/tablet/mobile: BLOCKED — automatic browser approval review
failed vì model at capacity, thao tác không được thực hiện. Không suy diễn
visual parity hoặc touch/screen-reader acceptance từ test render.
Final verdict: implementation verified by targeted checks; visual acceptance
BLOCKED pending browser access. Không tạo file kiểm thử tạm.

Commands/results: CourseCategoryManagementTest 20 PASS (188 assertions at the
final verification run); UI Kit CSS/dialog tests 8 PASS; full Pint and targeted Pint PASS;
Vite build PASS (67 modules, 4.78s); docs:lint PASS (94 existing allowlisted
metadata items, no new issues); route list retains 12 category endpoints;
git diff --check PASS. Full PHP suite not repeated for presentation-only scope.
Requirement traceability: category tests cover visibility/filter/empty/pagination
and business invariants; shared CSS tests cover opt-in isolation/tokens/entries.
Browser geometry, keyboard/touch, text overflow and VI/EN visual fidelity remain
unverified. No verified HIGH findings; browser acceptance remains open.

### Category list visual follow-up — 2026-10-19

Browser returned one screenshot of the authenticated category list in the
current narrow pane. Observed: filter fields stacked in one column, labels and
search button visible, two category rows rendered, horizontal scrollbar inside
the table region, footer wrapped. No screenshot file was saved. This is a
single-pane observation, not a measured mobile device/viewport comparison.

Subsequent browser capability/documentation and accessibility reads timed out
at automatic permission review. PC/tablet viewport changes, reference comparison,
filter interaction, keyboard scrolling and touch remain BLOCKED/unverified.
No additional CSS/Blade or business changes were made from this incomplete
visual inspection; no new visual defect was established by the available image.

### Category list correction to the actual UI Kit list — 2026-10-19

Owner rejected the earlier mixed ui-table/custom ui-list layout. This correction
supersedes that presentation: use the original mk-section, mk-section-head,
mk-tablebar, mk-table-scroll, table and mk-table-foot/mk-pages structure. Filters
use the original ui-field/mk-search, ui-search and ux-combo markup; actions use
ui-button and ux-row-edit. Removed the ui-list-* CSS introduced by the previous
attempt. No new Blade Component, module stylesheet or UI Kit source edit.

The Alpine status-combobox adapter binds the existing named native select,
retaining no-JS fallback and the same GET keyword/status payload. Supports text
search, clear, ArrowUp/Down, Enter, Escape, outside click and focus leaving the
field. CSS remains the sole presentation source. Link-only adapters normalize
button typography to the demo's native button; mk-text-cell adapts alignment
for real text columns; mk-empty keeps copy within the visible scroll container.
No demo handlers, fake rows, bulk selection, reporting tabs or totals were added.
Category status stays read-only text as before; edit still navigates to the
existing edit route. Page size remains the controller's fixed 10: the matching
size display is disabled rather than pretending to offer 5/20-row behavior.
New VI/EN keys label this display and the combobox's empty search result.

Classification: Existing-Feature Change; Initial/Final Audit Level MEDIUM;
Escalation None. Same documents, source of truth and business invariants as the
adoption audit above. Dependencies/consumers: shared Admin/Teacher category index
view, scoped shared CSS and existing Alpine runtime; no other forms migrated.
Files changed in this correction: category index Blade, shared CSS, category
feature tests, VI/EN lf.php dictionaries and this document. No new repo files.

Browser evidence: authenticated Admin at 1440x900, 768x1024, 375x812; reference
and live page explicitly measured at the same viewport and device scale 1,
same browser and Arial. Seven corresponding controls/cells (search input and
button, primary create link/button, ordinary table header, edit action,
combobox control and input) compared for height, font family/size/weight/line
height, padding, border width/radius, background and text color: zero differences
at all three sizes. Screenshots inspected at all sizes, no document-wide
horizontal overflow. This is sampled computed-style evidence, not exhaustive
pixel parity for differing datasets/layout shells or every interaction state.

Observed combobox no-results text and keyword search “Sơ” returning one real row,
keyboard selection active, GET submit and retained status in pagination
URLs, Escape close/focus retained on the combobox, inactive empty state and clear
filters. Tablet/mobile dropdown bounds stayed inside viewport; mobile table
ArrowRight moved scrollLeft to 40. Empty-state copy initially extended beyond
the mobile visible area: fixed and measured left 47/right 328 at viewport 375.
Existing local data has only two rows: real multi-page navigation is covered by
feature tests, not claimed as browser-tested. Teacher/EN browser rendering,
physical touch, screen readers and exhaustive hover/focus pixel comparisons
remain unverified. No mutations performed in browser.

Verification: CourseCategoryManagementTest 20 PASS / 194 assertions, including
new actual pagination-query preservation assertions. Shared CSS/dialog Node
checks 8 PASS. Targeted Pint PASS; Vite build PASS (67 modules, 4.30s), existing
backend/auth/student/app bundle hashes unchanged. Full PHP suite not repeated
for scoped presentation changes. docs:lint and diff checks are run at final
closure. Final Verdict: PASS WITH DOCUMENTED RISKS for the scoped correction;
no claim of full application/device visual parity. The earlier browser BLOCKED
record remains historical and is superseded only for checks described here.
Temporary original reference copies used by the existing preview server are
removed after inspection; no new test logs/JSON/screenshots retained in repo.

## Category create/edit adoption — 2026-10-19

Owner explicitly requested the category create/edit forms after the list. The
shared category form now uses ui-section/ui-grid/ui-field, the original input,
textarea, ui-check/ui-switch, ui-upload and ui-button contracts. No other
business forms migrated. ui-field--full spans the existing two-column grid;
ui-form-label-row and ui-form-media provide layout only, ui-field__error exposes
existing server validation errors. All visual rules remain in Shared CSS.

Load the separate resources/js/admin-ui-kit-controls.js asset in these adopted
views. Opt in real single native selects with data-ui-kit-select; supply
localized data-ui-empty-label/data-ui-clear-label. The adapter retains the
original named select and its real option values/disabled/required state,
updates it with input/change events, and changes only accessible presentation.
Native fallback remains if JavaScript does not run. Exported initUiKitSelect
is idempotent and returns destroy() for cleanup; multiple selects are outside
this adapter's scope. Search, clear when a blank option is allowed, ArrowUp/Down,
Enter, Escape, outside click and blur are supported. IDs must be unique. This
is an application adapter, not the demo JS or a new UI framework.

Existing authoring-media-upload, authoring-media-row, upload-hint and
media-preview-modal components receive an explicit ui-kit flag only from the
category form. Their default remains the existing presentation, verified with
related course-media tests. Signed URLs, thumbnail state presenter, media usages,
replace/remove field names, formats/real effective upload limit and file input
names are retained. Preview uses the shared native ui-modal with existing
Alpine show/close state; no media endpoint, permissions or save behavior changes.
No new Blade Component. Controller, tenant/auth, ownership, request validation,
SEO preservation, automatic slug rules and database operations are untouched.

Regression audit: Existing-Feature Change; Initial/Final MEDIUM; Escalation None.
Reason: scoped forms plus explicit opt-in presentation branches in shared media
components. Documents reviewed: README, LF-INDEX routing, Guardrails, Regression
Audit, Tech CSS, Form Design Standard and this contract. Source of truth remains
the approved original UI Kit and Shared CSS. Dependencies/consumers reviewed:
Admin/Teacher category forms, other course media consumers of the default
components, existing Alpine and Vite. New file: admin-ui-kit-controls.js. Changed:
category create/edit/partial, four existing media Blade components, shared CSS,
Vite input, VI/EN clear-selection label, category/attached-media tests and the
routing/usage docs. No production layout or original UI Kit file changed.

Native browser checks on authenticated Admin: create and edit at 1440x900,
768x1024 and 375x812, device scale 1; no document horizontal overflow. Grid is
two columns on PC/tablet and one on mobile. Text inputs measure 36px and upload
areas 85px. Existing edit values and image remain visible. Create name typing
retained the original automatic slug behavior; parent keyboard selection sets
the real parent_id. Mobile real-image preview opened inside viewport, Escape
closed it and focus returned to the Xem opener. Browser did not save records or
upload/remove production media. Default controls compared against the original
UI Kit at the same three viewports/browser/scale: seven text/label/textarea/
upload/combobox/button samples matched ten computed properties per size.
The eighth switch sample exposed a Tailwind 1px border absent in the original;
Shared CSS now explicitly sets border:0, verified in browser. Unused native-input
font/color properties still differ under the host reset; no text is rendered
by the switch input. Exhaustive pixel parity is not claimed.

Tests updated retain payload, SEO, sequence, tenant/teacher boundaries, media
attachment/removal and default shared-component coverage. Added old-input and
server-error association assertions. PHP related suites: 79 PASS / 844 assertions;
Node suite: 163 PASS. Pint targeted PASS, Node syntax check PASS; Vite build PASS
(68 modules), backend/auth/student/app hashes unchanged. docs:lint and diff
checks run at closure. Full PHP suite not repeated for scoped presentation.
No baseline failure remains in the targeted suites; obsolete visual assertions
were updated, an initial Blade syntax mistake and switch border were corrected.

Unverified: physical touch/coarse-pointer devices, VoiceOver/NVDA, Teacher/EN
visual rendering, native file chooser/network uploads on real devices and every
interaction's pixel comparison. Upload behavior is covered by PHP integration
checks. Final verdict: PASS WITH DOCUMENTED RISKS for the named form adoption.
No automatic rollout. Temporary reference copies removed; no new test screenshots,
logs or JSON retained. Browser viewport overrides reset after inspection.

Closure checks for category forms: final category/attached-media rerun 23 PASS /
240 assertions; documentation suites 20 PASS / 22 assertions; docs:lint and diff
checks PASS. Keyboard-active combobox option uses the existing option focus
presentation through data-ui-active; confirmed active descendant/highlight in
browser with no console warnings/errors. No files changed under app, routes,
database, config, production layouts or docs/ui-demo. Reference staging copies
were removed and the viewport override was reset.

### Owner-requested list layout refinement — 2026-10-19

Owner requested a more balanced category list layout after reviewing its image.
The embedded lf-admin-ui--list variant removes the duplicate canvas inset and
initial section margin, uses the existing backend h1 for section/table labelling,
and removes the repeated inner title. Filters and create action share one
mk-tablebar--filters row on desktop, wrap on tablet and stack on mobile.
The footer remains the single result range/count and pagination area. Shared
column contracts keep sequence (64px), status (150px), action (96px) compact;
parent is 26%, name uses remaining space, sequence is centered. These are the
Owner-requested layout adjustments; control typography/colors/states and original
UI Kit remain unchanged. No module CSS, layout, query or business behavior edit.

Existing-Feature Change, initial/final MEDIUM, no escalation; same governance,
source of truth and invariants as the adoption audit. Files: category index,
Shared CSS, existing presentation assertion and this usage record. New files:
none. Browser inspected at 1440x900, 768x1024 and 375x812: one page heading, no
document horizontal overflow; table keeps its own horizontal scroll when needed.
Targeted category suite 21 PASS / 204 assertions, CSS isolation/token tests
3 PASS, targeted Pint PASS, Vite build PASS (68 modules, 4.22s); docs:lint and
diff checks run at closure. Physical touch/screen readers and exhaustive pixel
state comparison remain unverified. Final verdict PASS WITH DOCUMENTED RISKS.
No new test artifact files; viewport override reset after review.

### Owner typography amendment — 2026-10-19

Owner requested a consistent 14px primary text size across content, labels,
inputs, buttons and data tables. Both canonical table variants (ui-table and
mk-section table) now use 14px instead of the reference's 13px. ui-button
explicitly uses the existing Arial 14px/20px control contract. Category status
is table data, so its caption class was removed and it inherits the table size.
Secondary helper/slug/caption text keeps its existing smaller size; heading
hierarchy, weights, colors and spacing are retained. Existing coarse-pointer
input sizing remains the responsive reference exception. This is an explicit
Owner-approved deviation from the original table typography, implemented only
in Shared CSS; the original UI Kit remains untouched. No business change.

Existing-Feature Change; initial/final MEDIUM, no escalation. Scope: Shared CSS,
category status markup and CSS usage/policy docs. Verification at closure uses
category tests, shared CSS checks, build, targeted Pint, docs lint/diff and browser
computed font sizes where available; not a new claim of source pixel parity.

Typography closure: browser measured table/header/data/status/label/button at
14px on 1440/768/375px viewports, with no document horizontal overflow. Category
suite 21 PASS / 204 assertions, CSS checks 3 PASS, targeted Pint, build (68 modules,
4.08s), docs:lint and diff check PASS. No new test artifacts or UI Kit source edits.
Final verdict PASS WITH DOCUMENTED RISKS; physical touch and exhaustive visual
state comparisons remain outside this sampled check.

### Owner pagination typography amendment — 2026-10-19

Owner extended the 14px standard to pagination: mk-page/ui-pagination buttons,
mk-pages select and mk-footnote result summaries use 14px. This supersedes the
reference's 12px footer summary/page-size text only; other helper/caption text
stays unchanged. Shared CSS is the only presentation change; original UI Kit,
paginator URLs, fixed page size and business behavior remain untouched.
Existing-Feature Change, initial/final MEDIUM, no escalation. Verification:
shared CSS checks, category tests, build, docs lint/diff and sampled browser
computed sizes. No new files or temporary test artifacts.

Pagination closure: computed page/summary/page-size text is 14px at 1440, 768
and 375px, with no document overflow; viewport reset. Category tests 21 PASS /
204 assertions, CSS tests 3 PASS, build, docs lint and diff check PASS. Full
suite not repeated for this typography-only change. Final verdict PASS WITH
DOCUMENTED RISKS; physical device and exhaustive pixel checks remain unverified.

### Owner pagination grouping amendment — 2026-10-19

Owner requested the result summary and pagination as one right-aligned area.
Canonical mk-table-foot now justifies its summary and mk-pages to the right;
wrapped page controls also align right on mobile. Shared summary translation:
“Hiển thị :from–:to / :total dòng” / “Showing :from–:to / :total rows”. Actual
paginator bounds are used, including 0–0 / 0. Font remains 14px. No query,
page-size, business or original UI Kit changes. Existing-Feature Change, initial/
final MEDIUM; no escalation. Scope: Shared CSS, category footer, VI/EN text and
this record. No new files; targeted tests/build/lint and browser checks at closure.

Grouping closure: browser confirms summary and controls right-aligned at 1440,
768 and 375px, 14px text and no document overflow; viewport reset. Category suite
21 PASS / 204 assertions, CSS checks 3 PASS, build, targeted Pint, docs lint and
diff check PASS. No temporary artifacts. Final verdict PASS WITH DOCUMENTED
RISKS; physical touch and exhaustive visual states remain unverified.

## Category list status switch and product count — 2026-10-19

Owner approved two list refinements on `/admin|teacher/course-categories`
("áp dụng UI KIT"): status uses the UI Kit `ux-row-switch` instead of plain
text, and a new "Số sản phẩm / Products" column. No new status visual was
invented; a short-lived pill variant was removed in favour of the UI Kit switch.

Each switch is a `role="switch"` submit button inside a POST form (CSRF) to the
existing `<prefix>.course-categories.toggle-status` route; aria-checked mirrors
status, aria-label uses new key `LF_course_category_action_toggle_status`.
Controller toggle logic, tenant scope and the success flash are unchanged; works
without JavaScript and redirects back with the current filters/page. Existing
behaviour noted, not changed: toggle/edit check tenant only, not teacher
ownership; teachers only see switches on categories they created.

Shared CSS adds only `mk-col-count`/`mk-count-cell` (right-aligned, tabular
numbers). The controller counts `core_course_products` per category with a
correlated subquery filtered by `products.customer_id` = current tenant, so
another tenant's rows never count.

Classification: Existing-Feature Change; Audit Level MEDIUM; no route, schema,
auth or business-rule change. Files: CourseCategoryController (count only),
category index Blade, shared CSS, VI/EN lf.php, CourseCategoryManagementTest.

## Category list live filter — 2026-10-19

Owner request: choosing a status filters at once; typing in the search box
filters as you type (Enter or the search button still work).

`initUiKitLiveFilter` in `resources/js/admin-ui-kit-controls.js` (opt-in via
`form[data-ui-live-filter]`) intercepts the GET form submit, fetches the same
URL and swaps only `[data-ui-live-region]` nodes (`table`, `foot`,
`clear-filters`) parsed with DOMParser; no HTML string sink. The form itself is
never replaced, so the search box keeps focus and caret. Typing waits 350 ms
after the last keystroke, never runs during IME composition (Vietnamese
Telex/VNI) and skips a query identical to the last one. The status combobox
calls `requestSubmit()` only when its value changes. URL updates with
`history.replaceState`; pagination resets to page 1. Only the latest request
may touch the page (sequence counter + AbortController). HTTP error, redirect
(e.g. expired session), missing region or network error loads the same URL
normally. Without JavaScript the form submits as before. Server, route, query
and tenant scope unchanged. CSS adds `ui-live-slot` (display: contents) and a
dimmed table while `aria-busy`; footer summary is `role="status"`.

Verification: tests/js/admin-ui-kit-live-filter.test.mjs 11 PASS (stale
response, late body, late failure, debounce, IME, fallbacks); mutation check
of 6 guards all killed. CourseCategoryManagementTest 22 PASS. Browser (static
rendered pages, 1440x800): typing "So" swapped to 1 row with focus and value kept,
clear-filters appeared; choosing "Không hoạt động" fetched `status=inactive`
without page reload. Not verified on the live authenticated session, real
Vietnamese IME, mobile or screen readers.

### Category list balance fixes — 2026-10-19

Owner screenshot showed a horizontal scrollbar under the empty state and two
"Xóa bộ lọc" buttons. Measured at 1440px: table 1143px vs scroll container
1142px, because the empty-state block (`100cqw - 36px`) ignored the 1px cell
border; it is now `100cqw - 37px` (scrollWidth equals clientWidth). The empty
state no longer repeats the clear-filters button when filters are active; the
toolbar one remains and the create button still shows for an empty tenant. The
magnifier shown in a focused combobox is the original UI Kit design, unchanged.
Verification: CourseCategoryManagementTest 22 PASS (new count assertion fails when
the duplicate is restored); browser check on a rendered empty-filter page.

## Media slot for category images — 2026-10-19

Owner asked for media upload like the image tile: data shows inside the tile, hover
View/Remove, replace by removing then choosing again. New shared component
`x-ui-media-slot` (+ `initUiKitMediaSlot`, UI Kit CSS `ui-media-tile*`, `ui-sr-only`),
applied to the category Thumbnail and Banner; the UI Kit branches of
`authoring-media-row`/`authoring-media-upload` (used only by categories) were removed,
the legacy branches are unchanged. Server contract unchanged: same file input names,
`remove_*_media` flags (hidden `0`/`1` instead of a checkbox), same validation and sync.
Behaviour rules and the not-yet-implemented multi-file case are in
[LF-Media.md §2.1](../platform/LF-Media.md).

Classification: Existing-Feature Change; Audit Level MEDIUM; no route, schema, tenant or
auth change. Files: ui-media-slot component, category form partial, authoring-media-row,
authoring-media-upload, admin-ui-kit-controls.js, admin-ui-kit.css, VI/EN lf.php (5 keys),
tests (Node media-slot suite, CourseProductCategoryAttachedMediaUiTest).

Verification: Node suite 14 media-slot tests (object URLs revoked, flag/pending state,
cancelled picker, reset, restored file, idempotent destroy; 10 guard mutations all
killed); UI Kit CSS tests PASS; PHP category tests PASS. Browser on rendered edit/create
pages: saved image fills the tile, hover shows eye/trash, Remove empties the tile and sets
the flag, a chosen file appears in the tile and opens the preview, FormData carries the
file and flags, mobile shows actions always without horizontal overflow. Not verified:
real file chooser, an actual save round-trip in the browser, screen readers, other kinds
(video/audio/document) which are not wired to any form yet.

## Category create/edit as one popup (3 columns at first, now 2) — 2026-10-19

Owner decision: category create and edit open in a popup UI KIT (3 columns) from the list;
there is **one** form, no separate create/edit pages and no no-JavaScript fallback. This
supersedes "Category create/edit adoption" above (page forms).

Server: `create`/`edit` return only the form fragment (`course-categories/dialog.blade.php`
including `partials/form.blade.php`) and answer 404 unless the request is XHR
(`X-Requested-With`). `store`/`update` answer JSON when `expectsJson()` (201 / 200
`{message}`; validation 422 `{errors}`); a non-JSON request keeps the previous redirect to the
list. Validation, tenant scope, ownership and media sync are unchanged. Field ids are
prefixed `category-form-*` because the list's status filter already uses `id="status"`.

Client: `resources/js/admin-ui-kit-dialog-form.js` (new, shared, opt-in via
`.lf-admin-ui[data-ui-dialog-form-scope]` and one empty `<dialog data-ui-dialog-form>`) loads
the fragment on `[data-ui-dialog-form-open][data-url]`, initialises selects and media slots,
submits with fetch (files included), marks per-field errors, closes on success, shows the
server message and fires `ui-dialog-form-saved`, which makes the live filter refetch the
list. Unsaved changes (compared to a snapshot taken after controls settle) are protected by
a nested confirm dialog for Cancel, ×, and Escape; Escape inside the confirm only closes the
confirm. While saving, the submit and cancel buttons are disabled and Escape is ignored. A
newer open or a close makes late responses no-ops. Long content scrolls inside
`ui-modal__body`; header and footer stay put. The static adapter
`admin-ui-kit-dialog.js` is not used here (it cannot load content). New shared CSS:
`ui-field-stack`.

Classification: Existing-Feature Change; Audit Level MEDIUM; no route, schema, tenant or auth
change. Files: CourseCategoryController, course-categories index/dialog/partials/form (create
and edit pages deleted), dialog-form JS, live filter listener, UI Kit CSS, vite config,
VI/EN lf.php (7 keys), tests.

Verification: Node suite 203 PASS (new: 14 dialog-form tests, mutation check of 11 guards, 1
survivor-equivalent pair documented as redundant guards); PHP category + media UI tests PASS
(fragment structure, ajax-only 404, JSON 422/201/200, index dialog contract). Browser, on
rendered pages with a stubbed fetch (no live server session): 3-column popup, parent
combobox list not clipped, slug auto-fill, 422 messages inline with focus, Escape asks and
Escape in the confirm keeps editing, discard closes, success disables buttons then closes
with flash, list refreshed, focus back on the opener, edit shows the saved image, 520px
viewport scrolls the body, 375px viewport is one column. Not verified: real server
round-trip (CSRF, file upload through the popup) in a browser, screen readers, second Escape
in Chrome without interaction (browser close-watcher may close without `cancel`), focus after
list refresh when the edited row's button is replaced.

### Category popup switched to 2 columns — 2026-10-19

Owner asked to go back from 3 to 2 columns, with Description no longer full width.
`data-columns="2"` (800px); order: Parent | Name, Slug | Status, Description | Featured,
Thumbnail | Banner. The temporary `ui-field-stack` CSS was removed. Behaviour, server contract
and the verification notes above are unchanged. Checked in a browser on the rendered
create popup at 1440px (two 366px columns); category PHP tests PASS.

### Category popup: hover hint and rebalanced layout — 2026-10-19

Owner asked for the "Định dạng… · Tối đa…" line to appear only when hovering the upload tile,
and for the popup layout to be rebalanced. The hint is now the slot content of
`x-ui-media-slot` (`.ui-media-slot__hint`, referenced by the file input's `aria-describedby`).
Where hover exists and the screen is wider than 520px it is a tooltip above the empty tile
(opacity, so it stays in the accessibility tree; shown on tile hover or keyboard focus, not
for a filled tile); on touch and narrow screens it stays a visible caption. The dialog form
keeps any existing `aria-describedby` when it marks and clears field errors.

Layout (2 columns): Parent | Name, Slug | Status, then left `ui-field-stack` (Description +
Featured switch) and right `ui-field-row` (Thumbnail and Banner tiles side by side). New shared
CSS: `ui-field-stack`, `ui-field-row`. Verification: PHP category and media UI tests PASS
(hint inside the slot and described-by assertion), Node UI Kit suites PASS; browser on rendered
pages: balanced rows, tooltip above each tile inside the dialog body without scrollbars.
Not checked on a touch device or with a screen reader.

### Category list edit icon — 2026-10-19

The row edit button uses the UI Kit reference icon exactly (lucide v0.468.0 `square-pen`, same
two paths as `docs/ui-demo/ui-kit-developer`, round caps/joins, `ui-icon` 16px, stroke 1.7
from Shared CSS) instead of the earlier hand-drawn pencil. Checked in a browser: 30x30 button,
primary colour; list test asserts the path.

### Category list result messages as a toast — 2026-10-19

Owner chose a toast over the green bar (which pushed the list down and never went away), with
the condition that it must not cover the account menu. The toast region is fixed at the
bottom-right (16px gutter on narrow screens), so the header is never covered. New shared
pieces: `ui-toast-region`, `ui-toast` (+`--danger`), `ui-toast__text/close`, token `--ui-success`,
and `showUiToast` / `hideUiToast` / `initUiKitToasts` in `admin-ui-kit-controls.js`: success
toast auto-hides after 4 s (`data-ui-toast-timeout`), a new message restarts the timer, a load
failure toast (timeout 0) stays until closed with ×; a server flash message
(`session('success')`, e.g. after toggling status) is shown as the same toast on load. This is a
new visual, not in the original UI Kit; it follows the Owner's approval in chat.
Verification: Node 4 toast tests; PHP test for the region and the flash; browser on a rendered
page: toast at the bottom-right corner (380x50 at 1036,826 in a 1440x900 viewport), visible at
2 s and hidden at 4 s. Not verified: touch, screen readers, several toasts at once.

### Toast restyled and moved to the top right — 2026-10-19

Owner: the success message should have a light green background with standard green text, and
sit at the top right of the form, below the top menu (not covering the account menu). The toast
region is now `top: 78px; right: 24px` (the `.admin-header` is 66px high; 16px gutter on narrow
screens). Success: background `#dcfce7`, border `#bbf7d0`, text `#166534` (tokens `--ui-success`,
`--ui-success-soft`, `--ui-success-border`); danger toast uses `--ui-danger-soft` with the
existing invalid/danger colours. Supersedes the bottom-right position above. Checked in a
browser: toast box 1036..1416 x 78..128 in a 1440px viewport, header bottom at 66, computed
colours as above.

### Required fields reported inline — 2026-10-19

The browser's own bubble ("Please fill out this field.", in the browser language) is replaced by an inline
message under the field. The dialog form has `novalidate`; on submit `admin-ui-kit-dialog-form.js` checks every
`[required]` control, reports all empty ones at once with the same red border and `ui-field__error` text as server
errors ("Vui lòng nhập :field." / "Vui lòng chọn :field.", EN equivalents from `lf.php`, `:field` is the label
without `*`, lower-cased), focuses the first one and sends nothing. A field's message disappears as soon as it has a
non-blank value (typing, or choosing in a select). Messages are linked with `aria-invalid`/`aria-describedby`
(an existing hint description is kept). The server still validates and answers 422 with the same display.
Verification: Node dialog-form tests 18 PASS (6 mutations killed); PHP asserts `novalidate`, `required` and the
message attributes; browser on a rendered popup: empty name -> "Vui lòng nhập tên danh mục.", no request, focus in
the field, message cleared on typing. Not verified: screen reader announcement, selects whose empty option is
selectable (only text inputs and the status select are required today).

### Category delete — 2026-10-19

Owner: a category with no attached product may be deleted. Because `core_course_templates.category_id`,
`core_course_products.category_id` and `core_course_categories.parent_id` are RESTRICT foreign keys (and the
database rule already forbids deleting a category used by a template), deletion is allowed only when all three are
empty; otherwise the server answers 409 with the reason and count. `DELETE /course-categories/{id}`
(`course-categories.destroy`, admin and teacher): tenant scoped, teacher only for categories they created, row
locked and re-checked inside a transaction, image usages detached first, then the row is deleted; a late FK
violation is reported as "in use". JSON for XHR (200 / 409), redirect with flash otherwise.

List UI: a trash button (lucide `trash-2`, `ux-row-delete`) beside edit; disabled with a tooltip giving the reason when
the counts (`product_count`, `template_count`, `child_count`, computed per row in the index query) are not zero.
Clicking asks through the shared `window.LFConfirm` (danger tone, Cancel focused), then
`admin-ui-kit-row-actions.js` sends the request; success shows the toast and refetches the list, a refusal shows the
reason in the error toast. Verification: PHP delete tests (empty delete + usage detach, blocked by product/template/
child with counts, tenant isolation, teacher ownership, guest 401, non-JSON redirect, index button states); Node 5 row
action tests (6 mutations killed). Not verified: the race-condition path (FK violation after the check) and a real
browser delete against a live server.

### Featured category marker — 2026-10-19

Owner chose a star instead of the grey caption text "Danh mục nổi bật" beside the name. The name cell is now
`mk-name-line` (name in `mk-name-text`, ellipsised when too long) followed, only for featured categories, by
`mk-featured`: a 14px filled lucide `star` in `--ui-featured` (#e0a100), `title`/`data-tooltip` with the existing
label, and a `ui-sr-only` copy of that label for screen readers (the icon is `aria-hidden`). Non-featured rows show
nothing. New visual, not in the original UI Kit, adopted on the Owner's choice. Checked in a browser: star 14px,
colour rgb(224,161,0), long names keep the star on the same line; PHP test asserts the markup and that the plain
caption is gone. Not verified: touch (tooltip needs hover) and the amber colour's contrast on the striped row.

## Course template list adoption — 2026-10-19

Owner asked to apply the Product Category approach to `/admin|teacher/course-templates`, list page first
(create/edit popup and delete come later). `course-templates/index.blade.php` now uses the UI Kit list: filters
(keyword + status combobox built from a native `select[data-ui-kit-select]`) apply as you type/choose through
`data-ui-live-filter`; result messages (`session('success')`) are a toast; table, footer and pagination use the UI Kit
markup. Status stays read-only text (four lifecycle values, so no switch); "Quản lý" is an edit-icon link to the
existing edit page. Controller, query, teacher scope and routes are unchanged.

Shared pieces extracted from the category list: `x-ui-list-toasts` and `x-ui-list-foot` (category list now uses
them too), live filter reacts to `change` on selects, CSS `mk-col-secondary`. Tests: template list and layout
tests updated to the new markup (the template list is no longer part of the legacy `admin-table` contract lists);
Node live-filter test for select changes. Browser (rendered page, stubbed fetch): combobox lists the 4 statuses,
choosing "Bản nháp" fetched `?status=draft`, typing fetched `?keyword=TOPIK&status=draft` with the search box
keeping focus, toast top-right. Not verified: teacher view in a browser, real server round-trip.

### Course template delete and lifecycle status pills — 2026-10-19

Delete (Owner: confirm first, only when nothing is attached): `destroy` already existed (no UI); it now answers JSON
for XHR (200 / 409) and keeps the redirect otherwise. A template can be deleted only when it has no sections,
lessons, activities, published versions, assigned teachers, products using it (`core_course_product_items`) and no
learning mappings (RESTRICT foreign keys plus the old rule). The row is locked and re-checked in a transaction, intro
image/video/document usages are detached first, and a late FK violation is reported as "in use". Access is unchanged
(`findTemplate`: admin, or the teacher who created / is assigned; others get 404). The list computes the first
blocking reason per row with one grouped query per table for the page's ids and disables the trash button with that
reason as the tooltip; clicking an enabled one asks through `window.LFConfirm`, then `admin-ui-kit-row-actions.js`
sends the request (same module as the category list).

Status column: templates have four lifecycle values, so (unlike the category on/off switch) they are shown as
read-only pills: `ui-status--draft` blue, `--active` green, `--inactive` amber, `--archived` grey (tokens
`--ui-warning`, `--ui-warning-soft` added). Changed on the Owner's suggestion; colours can be tuned in the CSS.
Verification: PHP tests (JSON delete, 409 for sections/teachers/products with counts, tenant and teacher scope,
non-JSON redirect with error, list button states, pill classes); browser on a rendered list: pill colours as above,
blocked row disabled with "Không thể xoá: Template còn 1 phần học.", confirm dialog (Cancel focused), DELETE request
and toast. Not verified: lessons/activities/versions/mapping blockers have no dedicated test (same code path as the
tested ones), the media detach and race paths, a real server delete.

Status pill refinement (Owner, 2026-10-19): no leading dot and regular weight (400) text; the colours per status are
unchanged.

### Duplicate status filter on the template list — 2026-10-19

Owner saw the status filter twice. Cause (reproduced in a browser): the same JS module was evaluated twice, because
Vite's dev server serves an HMR-updated module as `admin-ui-kit-controls.js?t=...` for importers while the page's own
tag loads the plain URL, so each copy built a combobox from the same `select[data-ui-kit-select]`. The old guard was a
module-level `WeakMap`, which each copy has separately. The guard now lives on the element
(`claimOnce`/`releaseClaim`, `data-ui-kit-ready-*`) for the select adapter, live filter, media slot, toasts, dialog
form and row actions, so a second copy skips an element that is already set up. Production builds ship one copy and
were not affected. Verification: Node tests for the guard; browser: the resource list showed both copies, only one
combobox is rendered.

### Course template create / edit-information popup — 2026-10-19

Owner asked for the category approach on the template list: Create and Edit open in a popup (2 columns).
Scope and what stays:
- **Create**: popup only. `GET course-templates/create` now returns the form fragment (`course-templates/dialog.blade.php`
  + `partials/dialog-fields.blade.php`) for XHR and 404 otherwise; the create page view was deleted. `store` answers JSON
  `{message, redirect}` (201) where `redirect` is the workspace page, so after creating the dialog continues there exactly
  like before (the created guidance is flashed for that page); validation answers 422.
- **Edit information**: new route `course-templates.form` (`GET /course-templates/{id}/form`, XHR only, same access rules as
  `edit`) renders the same fragment; `update` answers JSON `{message}` and the list refetches. The list row now has three
  actions: Edit information (popup), Manage (link to the workspace page, 6 tabs) and Delete.
- **Not changed**: the workspace page `course-templates/{id}/edit` (Structure, Teachers, Learning framework, Publish, History)
  and its Information tab still use the legacy `partials/form.blade.php`; teachers and learning frameworks are separate tabs,
  not part of this form. So the field list exists in two partials until the Information tab is moved to the popup (follow-up).

Popup content: title; category | publisher; status | difficulty; minutes per lesson | lesson count; short description |
description; then three media fields in a row: image tile, video (source select upload/embed; upload tile or URL input) and
document tile. Required fields come from `requiredFields()`; empty ones are reported inline. An archived template opens
read-only (fieldset disabled, save disabled). Field ids are prefixed `template-form-*`.
Shared pieces extended: `x-ui-dialog-discard` (nested "discard changes?" dialog), media slot carries `mime` and passes
`mimeType` in the preview event (non-image/video/embed kinds open in a new tab), the dialog form follows a `redirect` in the
JSON answer, and the required-message lookup finds the label of a select turned into a combobox.
Verification: PHP template tests (fragments, JSON create/update, ajax-only, scope, list triggers; 469 template-related tests
pass); Node 222 tests; browser on rendered pages with a stubbed fetch: popup layout, video source toggle (upload tile / URL),
inline required messages in Vietnamese, 422 mapping, redirect after create, edit popup with saved values and image preview.
Not verified: a real upload of video/document through the popup (large files, no progress indicator), the embed-video saved
state in a browser, teacher view, a real server round-trip.

### Template workspace: Information tab uses the same popup — 2026-10-19

Owner asked to leave one form only. The Information tab of `course-templates/{id}/edit` is now a summary (12 read-only
fields, status pill, archive action for an inactive template) with an "Edit information" button (read-only archived
templates: "View information") that opens the same dialog. The legacy `partials/form.blade.php` was deleted, so the field
list exists once (`partials/dialog-fields.blade.php`).

Details: the button loads `course-templates/{id}/form?context=workspace`; the fragment's form posts to
`update?context=workspace`, and in that case the JSON answer carries `redirect` to `?tab=information` plus a flashed success
message, so the page reloads with the new summary. Readiness links keep their URLs (`?tab=information#<field>`): the page
opens the dialog when the URL has a hash and the marked button is visible (`data-ui-dialog-form-hash-open`), and the dialog
puts the cursor in `#<field>` (tries the id prefix `data-field-id-prefix="template-form-"`, then the combobox of a select, then
the bare id), so links such as `#difficulty_level`, `#status`, `#intro_video_source` still land on the right field. The
workspace page loads the UI Kit CSS/JS (rules are scoped to `.lf-admin-ui`, wrapped around the Information panel only).
Verification: PHP tests (template summary, workspace save + redirect, readiness fragments all have a focusable field in the
dialog; 12 older tests rewritten from the edit page to the fragment; template-related suite passes), Node dialog-form tests
24 (7 mutations killed for focus/auto-open), browser on a rendered page: summary card, `#difficulty_level` opens the dialog
with the combobox focused, save posts to the workspace context and follows the redirect. Not verified: the guidance flash
after create on the real workspace, teacher view, a real server round-trip.

### Template popup layout adjusted — 2026-10-19

Owner: short and detailed description each take the full width (2 columns); the three uploads (image, video, document) share
one row; the embedded video is the last row. Layout now: title; category | publisher; status | difficulty; minutes | lesson
count; short description (full); description (full); uploads row (image | video tile | document); last row: video source
select | embed URL (shown only when the source is "embed"). The video tile appears in the uploads row only when the source is
"upload". Ids and names are unchanged, so readiness links (`#intro_video_source`, `#intro_image_file`, ...) still focus the
right field. New key `LF_course_template_embed_url_label`. Checked in a browser: embed state (select + URL in the last row,
image and document tiles in one row) and upload state (three tiles in one row).

### Template popup: media first, embedded video hidden, size policy; discard confirmation — 2026-10-19

Owner decisions on the template popup: (1) the embedded-video option is hidden for now: only image, video upload and
document remain; a template that already has an embed keeps it untouched (hidden `intro_video_source=embed` and URL are
sent) until a video file is chosen, which switches the source to `upload`; discarding the chosen file restores the saved
source (the media slot now fires a `change` event on discard so the form can follow); readiness links to
`#intro_video_source` focus the video tile through `data-ui-focus-alias`. (2) The three uploads form one row **above** the
descriptions. (3) Upload size policy, app-wide: a video up to **1 GB**, any other media up to **500 MB**
(`UploadLimit::MAX_VIDEO_KILOBYTES` / `MAX_OTHER_KILOBYTES`; `media.max_upload_kilobytes` and
`media.video_max_upload_kilobytes` can only lower them; PHP's `upload_max_filesize` / `post_max_size` still apply). Used by
template, product, activity video and media-library validation and by the upload hints. The local `.env` has
`MEDIA_MAX_UPLOAD_KILOBYTES=1048576`, which the policy cap now overrides to 500 MB for non-video media.

Discard confirmation: the nested custom dialog was removed. Closing a dialog form with unsaved changes (Cancel, ×, Escape)
now asks through the shared `window.LFConfirm` (danger tone, warning icon, Cancel focused; standard §26): title "Bỏ thay đổi
chưa lưu?", text "Bạn có thay đổi chưa lưu. Nếu đóng bây giờ, các thay đổi này sẽ bị mất.", action "Bỏ thay đổi". Applies to the
category and template popups. Verification: Node 228 tests (confirm flow, 5 mutations killed), PHP limits tests, browser
check of the layout, the hints (500 MB / 1 GB / 500 MB) and the confirmation. Not verified: a real 1 GB upload.

### Template popup: embedded video as a tile — 2026-10-20

Owner asked for the embedded video to look like the media tiles. The popup row is now: image | video (upload) | **embed video** |
document. `x-ui-embed-slot` + `initUiKitEmbedSlot` (`admin-ui-kit-controls.js`) reuse the media-tile look: empty = "+ Nhúng";
a click opens a small link editor under the tile (Enter or "Xác nhận" confirms, Esc / "Hủy" / an outside click cancel without
closing the dialog); a valid link fills the tile itself (YouTube thumbnail, the video icon for Vimeo) with View (embed preview) and
Remove on hover. `parseEmbedLink` mirrors `TrustedVideoUrlService` (HTTPS; YouTube incl. youtu.be/shorts/embed; Vimeo) so the
tile answers at once with "Chỉ hỗ trợ liên kết HTTPS của YouTube hoặc Vimeo."; the server stays the authority (422 on
`intro_video_embed_url` is shown on the tile through `data-ui-error-for`). The committed (normalised) link is the hidden
`intro_video_embed_url`.

Business rule kept: one intro video, an uploaded file **or** an embedded link. The hidden `intro_video_source` follows the last
action (`embed` when a link is set, `upload` when a file is chosen or a saved upload remains, else empty); entering a link empties
the video tile (a saved file is marked for removal, a chosen one discarded via `ui-media-clear`) and choosing a file empties the
embed tile (`ui-embed-clear`). A saved embed shows the server-decided thumbnail/preview (nothing to preview when readiness blocks
it). Verification: PHP (empty/saved/Vimeo tiles, source switching embed -> invalid -> upload -> none on the server), Node 241 tests
(embed tile 12 + link parser, 7 mutations killed), browser on rendered pages (editor, invalid link, Enter does not submit, Esc keeps
the dialog, thumbnail, both exclusion directions, saved embed preview) and on the real local app after logging in with the test
account (create popup with 4 tiles and the 500 MB / 1 GB hints, edit popup of a real template with a saved embed). Not verified:
saving a real template (no data was written), a real 1 GB upload.


## Shared page heading with primary action — 2026-10-20

Format for every UI Kit list page: title on the left, the single primary action (create) on the right, 16px between heading and
content, `h1` kept. The page fills `@section('page_actions')`; `layouts/backend.blade.php` then renders
`.page-heading.page-heading--with-actions` with `.page-heading__actions` (also `lf-admin-ui lf-admin-ui--list`, so UI Kit buttons
work there). Pages without the section keep the old heading.

Popup from the heading: the button carries `data-ui-dialog-form-open data-ui-dialog-form-for="<scope id>"`; the scope root has that
`id` plus `data-ui-dialog-form-scope`. `admin-ui-kit-dialog-form.js` opens triggers outside the scope only when the attribute equals
`scope.id` and the trigger is not inside the scope. The create button is not repeated in the filter form; the empty-state create
button stays in the table. Applied to course categories and course templates (tests: `BackendLayoutNavigationTest`,
`admin-ui-kit-dialog-form.test.mjs`). New list pages follow this format.

Button label: short verb + noun on the heading and the empty state (`LF_course_category_common_create_short` = "Tạo danh mục", `LF_course_template_common_create_short` = "Tạo template"). The long key stays as the popup title.

Secondary actions (export, import): the standard fixes only where they go; a page opts in when it really needs them. They sit in `.page-heading__actions` before the create button, which stays last on the right and is the only primary-coloured one. Secondary buttons use `ui-button--outline` with a short label such as "Xuất Excel", and move into a "…" menu once there are three or more actions. Export is not part of the default: before the first export page, decide filter scope (current filter or all), row limit, permission (teachers only their own data), columns, audit logging and tenant isolation (`customer_id`).

## Instant tooltip on row action icons — 2026-10-20

`.ux-row-edit` and `.ux-row-delete` with `data-tooltip` show a dark hint (same look as the upload-tile hint) at once on hover or keyboard focus, anchored to the icon's right edge so the scrolling table never clips it; only where hover exists. The native `title` is not used on these icons (it appears after a delay and would double the hint); the accessible name stays in `aria-label`. A disabled delete shows its blocker text the same way. Verification: related PHP tests, CSS token test, real app (hover on the edit icon).

## Media preview dialog — 2026-10-20

`x-media-preview-modal ui-kit` renders `dialog[data-preview]`: 760px wide, media centred in the body, video and embedded video at 16:9 (full width, at most 70% of the viewport height), images by their own size with the same height cap. Before this the embedded player kept the browser default 300x150 box in a wide empty dialog. Verified on the real app (embed preview of a saved template: dialog 697px, player fills the width); the YouTube content itself did not load inside the in-app browser pane, so playback was not verified.

## Template information tab redesign — 2026-10-20

`/admin/course-templates/{id}/edit?tab=information` follows the Owner demo `lf-template-information.html`: underline tabs (`.course-template-tabs`, wrapping, in `admin-pages.css`, shared by every template tab), one panel with head (title + "Sửa thông tin"), then three titled groups in the same order as the edit form (`.ui-summary-group`: general information with the status last, introduction resources, introduction content last) separated by a hairline. Name, short and long description span the full width; the three resources are bordered tiles (56px minimum height) with an icon and the file name (`—` when empty), as many 180px+ columns as fit (three on desktop) and one under 640px. `data-summary-field` ids and the edit popup (`data-ui-dialog-form-hash-open`) are unchanged. Also fixed: the summary items carried two `class` attributes, so the "wide" class never applied.

Resources can be viewed on the information tab: a tile with a file is a button (name plus View icon, hover and focus states) that opens the shared preview dialog (`dialog[data-preview]`): image and uploaded video inside the dialog, embedded video through the server-decided embed URL; a document opens in a new tab. A tile with nothing (or an embed the server will not preview) stays a plain tile. Verified on the real app for the image and the embedded video; not verified: an uploaded video and a document (the sample template has neither).

## Template content tab redesign — 2026-10-20

`/admin/course-templates/{id}/edit?tab=structure` (and the teacher variant) follows the Owner demo `lf-template-content.html`: one white surface (`course-template-section-card` plus `lf-admin-ui lf-admin-ui--list ui-structure`) with title and help, a segmented switch (direct lessons / by section), a toolbar band with "N bài học · M hoạt động" and the primary "+ Thêm bài học", numbered lesson cards, an activity bar ("Hoạt động (N)" folds the list, "+ Thêm hoạt động" on the right) and activity rows with type icon, the processing status as a pill and icon-only View/Edit/Delete.

Rules: markup keeps the legacy class names, ids and texts (tests and the read-only version pages share them); the new look is CSS scoped to `.lf-admin-ui.ui-structure`, so version pages are untouched. Icon actions are `.ui-row-action` with `aria-label`, a visually hidden text (`ui-sr-only`) and the instant `data-tooltip`; delete still opens the existing confirm modal and posts the same form. The "by section" panel gets the same surface, toolbar and lesson cards, while the section headers themselves are still the legacy look. Verified on the real app (desktop, 400px wide, fold toggle, delete confirm opens, by-section panel); not verified: lessons inside real sections, an audio activity's transcription form, the teacher area.

Revision (demo `lf-template-content (1).html`, same day): lessons are no longer boxed cards; each lesson is a group separated from the next by a horizontal line (`--ui-border`), 22px vertical padding, and the activity bar and rows are indented 42px so they align with the lesson title (no indent under 640px). The activity bar is a rounded band without a top border; activity rows have a hairline between them and none above the first. Icons: row actions use Lucide `eye`, `pencil` and `trash-2` (added `pencil` and `trash-2` to `backend-icon`, and `file-text` now has the three text lines); activity type icons are `file-video` (video), `square-play` (embedded), `audio`, `file-text` (document and default), `clipboard-check` (quiz), `video` camera (live class); the version page uses the same mapping. Verified on the real app (template 3: live class, documents, quiz) at desktop width; mobile was verified for the previous card layout, not re-checked for this one.

## Lesson create/edit/delete as a UI Kit popup — 2026-10-20

First step of moving the Content tab screens (lessons, then sections, then activities) to popups. The lesson form (direct lessons and lessons inside a section, admin and teacher) is now the `course-template-lessons/dialog.blade.php` fragment, fetched by `[data-ui-dialog-form-open]` buttons in the structure tab: the toolbar "+ Thêm bài học", "+ Gắn bài học" in a section, the empty state and the pencil icon of each lesson. The old create/edit pages and `partials/form.blade.php` are gone; the GET create/edit routes answer only XHR (404 otherwise).

Contract: store/update answer JSON (`201`/`200`, `422` with `errors` shown on the fields, `redirect` to `?tab=structure#…`) and flash `success`; the dialog follows `redirect` through the shared `followRedirect()` (it reloads when the target is the open page, because only a #hash change would not), and the reloaded tab shows the flash as a toast (the legacy green alert is hidden on the information and structure tabs). Delete is `[data-ui-row-delete]` with the shared confirm dialog; `row-actions` now also follows a `redirect` in the answer. A lesson that still has activities, or that another lesson unlocks after, has its delete button disabled with the reason (and the server answers `409` with the same reason). Conditional unlock fields (selected lessons, match rule, date) are Alpine inside the fragment; the select adapter's `change` drives them; the prerequisite checkbox list reports its error through `data-ui-error-for="prerequisite_lesson_ids"`.

Verification: lesson feature tests (28, incl. JSON create/update/delete for admin and teacher, 409 reasons, disabled buttons, fragment-only routes), Node tests (row-actions redirect), real app (create popup opens with location, required message, discard confirmation, unlock-rule fields appear, edit popup is prefilled). Not verified: saving or deleting a real lesson (no data written), the teacher area in the browser.

## Section create/edit/delete as a UI Kit popup — 2026-10-20

Second step of the Content tab (after lessons). The section form (title, description, parent section, allows lessons, display order) is the `course-template-sections/dialog.blade.php` fragment, opened from the "+ Thêm phần học" buttons (toolbar and empty state), the "+ Thêm phần học con" button of a section (the URL carries `?parent_section_id=`, validated against the template's sections and pre-selected) and the pencil icon of each section header. It reuses the one `dialog[data-ui-dialog-form]` of the structure tab (a scope holds a single dialog; two nested scopes would both react to a click), so the dialog title id is the neutral `structure-dialog-title` and the messages are shared with lessons. The old create/edit pages and `partials/form.blade.php` are gone; GET create/edit answer only XHR.

Same contract as lessons: JSON `201`/`200`/`422`, `redirect` to `?tab=structure#course-template-sections` with the flashed toast, delete through `[data-ui-row-delete]` and the shared confirm. A section with sub-sections or lessons has its delete button disabled with the reason (and the server answers `409` with it). The display order of a new section is read-only and follows the chosen parent (Alpine inside the fragment, driven by the select adapter's `change`).

Verification: section feature tests (19: JSON for admin and teacher, 422 on the title, 409 reasons, disabled buttons, fragment-only routes, parent prefill), real app (create popup, required message). Not verified in the browser: editing or deleting an existing section and the child-section flow (the sample template has no sections), saving real data.

## Upload progress in dialog forms, and the 1.5 GB video limit — 2026-10-20

`admin-ui-kit-dialog-form.js` sends the save by XHR instead of fetch whenever a file input holds a file, so the upload reports progress: `<x-ui-upload-progress />` (placed between `.ui-modal__body` and `.ui-modal__footer`) shows a bar (`role="progressbar"`), the percentage (at 100% the text becomes "Đang xử lý…" while the server works), and a "Hủy tải lên" button that aborts the request, hides the bar, keeps the form and what was typed, and says so in the footer feedback (not an error). While uploading, Save, Cancel, the × button and Escape are disabled (as for any save in flight) and the browser warns before leaving the page; the warning is removed before the server's redirect is followed. Without a file the save still uses fetch. Applied to the Template popup. Tests: 4 Node tests (XHR used, progress, locked close, cancel, 422, network error, no file → fetch).

Video limit: the policy for a video upload is now 1.5 GB (`UploadLimit::MAX_VIDEO_KILOBYTES = 1572864`, `MEDIA_VIDEO_MAX_UPLOAD_KILOBYTES`); every other media stays at 500 MB. It applies to every video field that uses `effectiveVideoKilobytes()` (template intro video, product intro video, activity video) and to videos in the media library. PHP's `upload_max_filesize` / `post_max_size` still lower the effective value (the hints show the effective one), so they must be raised to at least 1536M / 1600M. Speech-to-text keeps its own source limit (`MEDIA_STT_MAX_VIDEO_SOURCE_BYTES`, 1 GiB): a larger video uploads but cannot be transcribed.


## Activity create/edit as a UI Kit popup — 2026-10-20

Third step of the Content tab (lessons, sections, activities). The activity form (direct and in-section lessons, admin and teacher) is the `course-template-activities/dialog.blade.php` fragment opened from "+ Thêm hoạt động" and the pencil icon of each activity; it reuses the structure tab's single dialog (title id `structure-dialog-title`). The old create/edit pages, `partials/form.blade.php` and `partials/current-media.blade.php` are gone; GET create/edit answer only XHR. The show page and the legacy delete modal are unchanged (next step).

Layout: three titled groups inside the 2-column grid (`.ui-modal__section-title`): information (name, description, type, estimated minutes, then the block of the chosen type), learning phases, completion and access. Type blocks are Alpine `x-show`: video (media slot + transcription option), linked video, audio (slot + transcription), document (slot + PDF structure extraction), quiz id, live-class URL, plus the content-language checkboxes when a document or a transcription needs them. The long processing/limit explanations moved into a `<details>` ("Chi tiết điều kiện xử lý"); the upload hints now show the effective limits (video 1.5 GB, other media 500 MB) instead of the old fixed "1 GB".

Media: the three legacy upload tiles became `ui-media-slot`s (`activity_video_file`, `activity_audio_file`, `activity_document_file`); only the slot of the saved type shows the saved file and renders `remove_activity_media`; View opens the shared preview dialog (video/audio in a nested dialog, documents in a new tab). The browser still reads the duration of a chosen video/audio file; a wrong file type is discarded from the tile with an inline message. The save goes by XHR with the upload progress bar when a file is chosen.

Completion rules depend on the type: the six options are rendered once and `syncCompletionOptions()` enables the allowed ones (audio relabels "Nghe nội dung"/"Phần trăm đã nghe") and refreshes the select adapter, which re-reads its options each time it opens. Contract: JSON `201`/`200`/`422`, `redirect` to the lesson's activity panel, flashed `success` plus at most one `info`/`warning` about queued transcription/extraction (shown above the tabs after the reload).

Verification: activity feature tests (26, incl. JSON for admin and teacher, 422, the queued-transcription notice, fragment-only routes) and media integration tests (45) rewritten for the fragment; real app: create popup (groups, type switching, completion rules per type, wrong-type video discarded with message, valid file accepted), edit popup of a saved document (PDF tile, saved file/remove flag, locales, closing unchanged does not ask). Not verified in the browser: saving a real activity or uploading a large file, the audio transcription flow, the teacher area.

## Activity view and delete in the Content tab — 2026-10-20

Step 3 of the Content tab. The read-only "Xem" of an activity (the cases that used to open the `show` page: details only, no media file or external link) is now a popup: `course-template-activities/view-dialog.blade.php`, the same single dialog of the structure tab, with Close and Edit (Edit opens the edit form in place of the details). The `show` route answers only XHR; `show.blade.php` is gone. Media and external-link activities keep their preview dialog / new tab.

Delete is `[data-ui-row-delete]` with the shared confirm dialog and a toast after the reload (JSON `200` with `redirect`, flashed `success`); the legacy delete modal is removed. An activity that another activity unlocks after has its delete button disabled with the reason ("Không thể xoá: hoạt động khác đang mở khoá sau hoạt động này."), and the server answers `409` with it. Deleting still detaches the activity's media usages and recalculates the lesson duration.

Dialog fix: opening a form while another is shown (View -> Edit) closed the dialog first, and the `close` event of that old form tore the new one down, so nothing reappeared. The replaced close is now ignored (`replacedCloses`; Node test, mutation killed).

Verification: activity feature tests (27: fragment-only show, view/delete markup, JSON delete for admin and teacher, 409 and disabled button), Node 32 dialog-form tests, real app (view popup of a quiz, View -> Edit switch, delete confirm with the right message and Cancel; nothing was deleted). Not verified: a real delete through the browser (no data was removed), the teacher area.

## Back link above the page title — 2026-10-20

A backend page can offer a small "back to the list" link above its `h1`: fill `@section('page_back')` with `<x-page-back-link :href="$backUrl" :label="..." />`; the layout renders it in `.page-back` (13px muted text with a chevron, primary colour on hover, keyboard focus ring). It is navigation, not an action, so it is separate from `page_actions` and keeps the `h1`.

"Back to the same list" keeps the filters: `App\Support\ListReturnUrl::remember($request, $key)` in the list's `index` stores the full URL (filters and page) of the last list the user saw in the session, and `ListReturnUrl::get($request, $key, $fallbackRoute)` returns it on the detail page; with no list visited yet, or a stored URL that is not on the current host, it falls back to the plain list. Applied to the Template workspace (`/admin|teacher/course-templates/{id}/edit`, key `course-templates.<route prefix>`). To use it on another page: call `remember` in that list's `index`, pass `get(...)` as `backUrl`, and add the `page_back` section. Tests: `CourseTemplateManagementTest` (admin and teacher, filtered list, fallback, foreign host).

Lesson popup field order (2026-10-20): the display order (`sort_order`) is the last field, after the unlock rule and its conditional fields (match rule, date, prerequisite lessons), because it is an auto-suggested value that is rarely changed. Sections and activities already put it last.

## Media library list (`/admin/media`) on the UI Kit — 2026-10-20

The list follows the Owner demo `lf-media-list.html` with the category-list behaviour: instant filters (`data-ui-live-filter`; typing waits for a pause, selects and the object multi-select filter at once; the search icon still submits), regions refreshed without a reload (`tabs`, `clear-filters`, `summary`, `table`, `foot`), tabs as links with count pills (`.mk-tab[aria-current=page]`, `.mk-tab-count`), and the standard blue table header. Columns: STT, Media (44px tile with the real thumbnail; click opens the shared preview dialog for image/video/audio, documents open in a new tab; then name and file name), type and size, uploader and date, usage ("N nơi sử dụng" opens a dialog listing the owner type and the object name of every place), status pill (in use / unused) and actions (delete icon plus a checkbox, only for unused files; used files show "—").

New shared pieces: `initUiKitMultiSelect` (`admin-ui-kit-multiselect.js`; trigger with a summary, panel with search and checkboxes, rebuilt from `data-ms-options` when the controlling select changes, disabled until it has a value; the live filter reacts to its checkboxes but not to its panel search), and in `admin-ui-kit-row-actions.js` the bulk delete (`[data-ui-bulk-select-all]`, `[data-ui-bulk-item]`, `[data-ui-bulk-bar]`, `[data-ui-bulk-delete]` with ":count" in the confirm message) and `[data-ui-confirm-post]` (a confirmed plain POST, used by "Dọn file mồ côi"). All confirmations go through the shared LF confirm dialog; the native `confirm()` and the legacy delete modal are gone.

Server: `destroy`, `bulkDestroy` and `purgeOrphanStorage` answer JSON for XHR (`redirect` to the list URL with the filters, flashed success shown as a toast after the reload; a refusal is a `409` with its message) and still redirect for a plain form. The buttons receive URLs that already carry the filters being viewed. Tests: 37 library tests (JSON flows with filters, multi-select rendering, markup), 5 Node multi-select tests, bulk/POST row-action tests, live-filter test.

Verified on the real app: list and columns, tabs, instant filtering by owner type and by object (URL and rows follow), usage dialog, video and audio preview, purge confirmation. Not verified in the browser: bulk selection and deletes (the dev data has no unused media; covered by Node and feature tests), the mobile card layout.

Media library: the "Dọn file mồ côi" button moved from the filter form to the page heading (`@section('page_actions')`, outline style: a secondary, rarely used action) so the four filters stay on one row on desktop. A heading button is outside the list's scope, so it names the scope with `data-ui-row-actions-for="media-list"` and `admin-ui-kit-row-actions.js` handles it through a document listener (same idea as `data-ui-dialog-form-for`); the confirmation, request and toast are unchanged. Its explanation stays available as the `title` and a visually hidden description.


Row action order (2026-10-21): in the actions cell of a UI Kit list, the management icon ("Quản lý", the link into the record's workspace) comes first, then the edit/view icon, then delete. Only the template list has a management icon today (checklist icon, then edit information, then delete); lists without one keep edit then delete. The actions column itself stays where it is (last).

## Media library: selection column and the storage clean-up dialog — 2026-10-21

The list has no actions column any more. A checkbox column comes first (select-all in its header); only unused Media can be ticked, a used one shows a locked checkbox with the tooltip "Đang được sử dụng ở N nơi, không thể xóa." (`.ui-bulk-lock[data-tooltip]`, tooltip aligned to the start). Deleting is always "tick, then Xóa đã chọn" (one file or many) through the shared confirm dialog. The status column says it plainly: green "Đang sử dụng" with the number of places, or grey "Chưa sử dụng" with "Có thể xóa"; the usage column and its popup are unchanged.

Two different things, two controls: **unused Media** (still rows in the library, visible in the list) are deleted by selection; **orphan storage files** (left in storage, no Media refers to them, invisible in the list) are handled only by the heading button "Dọn kho lưu trữ", which opens `#media-cleanup-dialog` (`admin-ui-kit-cleanup.js`): step 1 scans (nothing is deleted; a waiting message), step 2 shows "Tìm thấy N file mồ côi trong M Media" with the run button (or "Không còn file mồ côi" and no button), step 3 purges in batches of 20 Media with a real progress bar while the dialog cannot be closed, then shows the totals (and how many Media could not be cleaned). An expired scan or a failure shows the error with "Quét lại".

Server: `GET /admin/media/cleanup/scan` stores the findings in the cache for 15 minutes under a token bound to the tenant and the admin and answers the counts; `POST /admin/media/cleanup/run` (token, offset, limit up to 25) purges one batch (the sweeper re-checks references per item, so something that became referenced is kept) and forgets the token after the last batch; an unknown or expired token is `410`. The older `purge-orphan-storage` route stays for plain form posts. Tests: feature test of scan and batch purge for one tenant only, 5 Node tests of the dialog, the markup tests of the list.

Verified on the real app: columns, locked checkboxes, status texts, and the dialog's scan to the "nothing to clean" state (the dev storage has no orphan files). Not verified in the browser: the run with a progress bar and the bulk delete (covered by the Node and feature tests).


Clean-up status label (2026-10-21): the heading button "Dọn kho lưu trữ" never changes (same look, text, icon, tooltip, always enabled). What is known about the storage is a small label `[data-cleanup-status]` beside it: `clean` ("✓ Kho đã sạch", success tint), `dirty` ("Còn file mồ côi ở N Media", warning tint) or `unknown` (hidden). The page renders only what is already known: `GET /admin/media/cleanup/status` answers `{items, files}` from a ten-minute cache (a scan on a miss), `cleanupScan` refreshes it, and it is forgotten when a Media is deleted (single or bulk), when the legacy purge runs and when a clean-up finishes. When the page rendered `unknown`, `admin-ui-kit-cleanup.js` asks the status URL after load and updates only the label; a scan and a finished run update it too (failures left count as dirty). Tests: feature test of the cache, the label and the invalidation; Node tests of the label updates and of the untouched button.

Media usage dialog (2026-10-21): the status column shows only the in-use / unused pill (the usage column already counts the places). The dialog lists each place as a bordered row with a type chip and the name (`.ui-usage-list`); when the owner has a page the whole row is a link (`owner_url` from `MediaFileController::ownerUrls`: template and activity open the template editor, the activity on its `structure` tab; cohort its detail page; product its edit page; category the list), otherwise a plain row.

Media list columns (2026-10-21): after the upload column come Status (pill plus a small line: "N nơi sử dụng" or "Có thể xoá") and a last Actions column with the manage icon (`ux-row-edit`), which opens the dialog with two tabs: usage places (linked rows) and sharing (placeholder until the sharing domain is approved; no behaviour yet). The separate usage column is gone.

Media manage dialog (2026-10-21, from the Owner design lf-media-sharing.html): one dialog (`.ui-share`) with header (icon, "Quản lý Media", file name and type), a tab pill (usage places, sharing), the usage list (rows with a divider and an open link) and the sharing panel (scope radios: private / selected teachers / public; teacher picker with search, avatar and a selected count; footer with Reset and Save). Sharing is a preview only: the teachers are the active teachers of the current tenant, Save shows "chưa lưu vào LF" and stores nothing, and Public is unresolved; both wait for the approved access contract in `quality/LF-Media-Sharing-Proposal.md`.

Media actions split (2026-10-21, from lf-media-two-actions.html): the last column now has two icons instead of one manage icon: "Nơi sử dụng" (link-2) opens a 480px dialog listing the places with an open link each, and "Quyền chia sẻ" (user-cog) opens a 480px dialog with a "Chia sẻ với" select (private / selected teachers and admins / public), the people picker and Cancel / Save. Sharing is still preview-only (nothing is stored). The two-tab manage dialog above is replaced.

Row action icon audit (2026-10-21): measured on the real app, every UI Kit row action (`.ux-row-edit`, `.ux-row-delete`, `.ui-row-action`) is a 30x30 button, 4px radius, 16px `ui-icon` with stroke 1.7, primary colour (danger: `--ui-invalid`), hover `--ui-color-eef0fb` (danger: `--ui-danger-soft`) and the instant `data-tooltip`. Fixed deviations: the content-tab `.ui-row-action` was 32x32 with a different danger red, and the usage dialog link was a bespoke 36px grey button; it is now a `ux-row-edit` with a tooltip. Pages still on the legacy look (products, cohorts, media categories) were not touched.

Usage dialog consistency (2026-10-21): the header count and the rows now always match, and every row has an open link. Cause of the report: two usages with the same type and name (a live activity and a published-version snapshot of an activity deleted later) shared one Alpine `x-for` key, so one row was hidden, and the snapshot had no link because its source activity was gone. Fixes: rows are keyed by index; a snapshot whose source activity (or template) no longer exists stays a version item (labels "Hoạt động trong phiên bản đã xuất bản" / "Phiên bản template đã xuất bản", name from the snapshot title) and links to its version page `admin.course-templates.versions.show`. Checked on the real app: all 15 listed media, count equals rows, no row without a link.
