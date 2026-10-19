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
