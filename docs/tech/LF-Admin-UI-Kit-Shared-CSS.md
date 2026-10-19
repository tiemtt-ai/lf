# LF Admin UI Kit Shared CSS

Version: 1.2

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
Entry được Vite build riêng, chưa được nạp trong layout hiện hành.
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
