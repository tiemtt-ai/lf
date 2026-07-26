# LearnForge Create/Edit Form Design Standard

Version: 1.1

Status: Official Standard

Scope: LF Admin Create/Edit Forms

Last Updated: 2026-07

---

# 1. Purpose And Authority

Tài liệu này là chuẩn presentation canonical cho form Create/Edit trong LF
Admin và các form Teacher Authoring sử dụng cùng LF admin form primitives.

Tài liệu kiểm soát layout, visual hierarchy, responsive behavior,
accessibility presentation và verification của form. Module vẫn là authority
đối với field availability, validation, authorization, submitted values và
business behavior.

Nếu có xung đột:

1. Governance, ADR, Domain và Database rules có quyền ưu tiên đối với business
   behavior và data ownership.
2. Tài liệu này có quyền ưu tiên đối với presentation của LF Admin Create/Edit
   form.
3. Chuẩn này **MUST NOT** được dùng để vượt qua authorization, validation,
   lifecycle, tenant isolation hoặc domain invariants.

Các từ khóa normative:

* **MUST** — bắt buộc.
* **MUST NOT** — bị cấm.
* **SHOULD** — cần thực hiện, trừ khi có lý do đã được ghi nhận.
* **MAY** — tùy chọn.

---

# 2. AI Agent Trigger

Các câu sau kích hoạt chuẩn này:

```text
Áp dụng thiết kế tiêu chuẩn
Áp dụng thiết kế tiêu chuẩn cho form
Áp dụng thiết kế tiêu chuẩn cho form tạo mới/edit
Apply the LF standard form design
Apply the standard Create/Edit form design
```

Khi nhận trigger, AI agent **MUST**:

1. Đọc tài liệu canonical này trước khi chỉnh sửa.
2. Inspect form hiện tại, shared components và business rules của module.
3. Áp dụng presentation rules bằng shared LF form primitives.
4. Giữ nguyên toàn bộ module-specific behavior.
5. Kiểm tra sidebar expanded, sidebar collapsed, tablet và mobile.
6. Thực hiện browser visual acceptance khi browser access khả dụng.
7. Báo cáo rõ mọi deviation cần thiết.

Trigger phrase **MUST NOT** được hiểu là quyền thay đổi business logic.

---

# 3. Scope

## Covered

* Admin Create forms.
* Admin Edit forms.
* Teacher-authoring Create/Edit forms khi dùng LF admin form primitives.

## Not Automatically Covered

* Public registration hoặc checkout.
* Student learning runtime.
* Search/filter toolbars.
* Read-only detail pages.
* Modal-only micro-forms, trừ khi task yêu cầu áp dụng rõ ràng.

Create/Edit của một module **SHOULD** dùng cùng partial/component khi kiến trúc
hiện tại hỗ trợ. Chuẩn này không quyết định field nào tồn tại hoặc được submit.

---

# 4. Design Principles

Form theo chuẩn **MUST**:

* Có một business flow rõ ràng từ trên xuống dưới.
* Dùng một primary form surface, trừ khi workflow có bằng chứng cần nhiều card.
* Nhóm field thành semantic sections theo nghiệp vụ.
* Dùng section-local grids.
* Giữ validation error gần field tương ứng.
* Reuse shared component trước khi tạo component mới.
* Dùng progressive disclosure cho conditional fields.
* Giữ visual parity giữa Create và Edit.

Form **MUST NOT**:

* Ép hai section không liên quan thành full-height columns bằng nhau.
* Giữ cột trống cho conditional content đang ẩn.
* Dùng fixed section height.
* Dùng whitespace nhân tạo để căn hàng.
* Tạo Product-, Category- hoặc module-specific duplicate của shared layout.

Heading, label, helper và error **MUST** có hierarchy rõ ràng. Helper text
**MUST** là secondary nhưng vẫn đủ contrast.

---

# 5. Canonical Form Anatomy

Shared implementation hiện hành nằm tại:

```text
resources/css/admin/admin-components.css
```

Canonical primitives:

| Responsibility | Class hiện hành |
| --- | --- |
| Form surface/card | `admin-card admin-form-card admin-form-surface` |
| Form container | `admin-form-standard` |
| Vertical business flow | `admin-form-flow` |
| Semantic section | `admin-form-standard-section` |
| Section header | `admin-form-section-header` |
| Section title | `admin-form-section-title` |
| Optional section helper | `admin-form-section-help` |
| Compact subsection | `admin-form-subsection` |
| Compact subsection title | `admin-form-subsection-title` |
| Inline policy information | `admin-form-inline-notice` |
| Decorative information icon | `admin-form-inline-notice-icon` |
| Label and compact metadata row | `admin-form-label-row` |
| Compact label metadata | `admin-form-label-metadata` |
| Responsive field grid | `admin-form-field-grid` |
| Main/compact field grid | `admin-form-field-grid--main-compact` |
| Field wrapper | `admin-form-field` hoặc shared `lf-form-group` hiện có |
| Full-width field | `admin-form-field--full` |
| Stable vertical flow | `admin-form-stack` |
| Read-only field | `admin-form-readonly` |
| Option group | `admin-form-option-group` |
| Option/checkbox panel | `admin-form-option-panel` |
| Compact option panel | `admin-form-option-panel--compact` |
| Conditional region | `admin-form-conditional` |
| Empty configuration state | `admin-form-empty-state` |
| Calculated read-only summary | `admin-form-calculated-summary` |
| Label/control/help/error | `lf-form-label`, `lf-form-control`, `lf-form-help`, `lf-form-error` |
| Action footer | `admin-form-footer` |
| Destructive/admin group | `admin-form-footer-danger` |
| Primary group | `admin-form-footer-primary` |
| Cancel action | `btn btn-secondary` |

Minimal generic Blade/HTML:

```html
<div class="admin-card admin-form-card admin-form-surface">
    <form class="admin-form-standard" method="POST">
        <div class="admin-form-flow">
            <section class="admin-form-standard-section"
                     aria-labelledby="identity-section-title">
                <header class="admin-form-section-header">
                    <h2 id="identity-section-title"
                        class="admin-form-section-title">...</h2>
                    <p class="admin-form-section-help">...</p>
                </header>

                <div class="admin-form-field-grid">
                    <div class="lf-form-group admin-form-field">...</div>
                    <div class="lf-form-group admin-form-field">...</div>
                    <div class="lf-form-group admin-form-field--full">...</div>
                </div>
            </section>
        </div>

        <footer class="admin-form-footer">
            <div class="admin-form-footer-danger">...</div>
            <div class="admin-form-footer-primary">
                <a class="btn btn-secondary" href="...">Cancel</a>
                <button type="submit" class="btn btn-primary">...</button>
            </div>
        </footer>
    </form>
</div>
```

Module **MAY** omit section helper hoặc destructive group content. Module
**MUST NOT** đặt business policy vào shared classes.

---

# 6. Form-Width Invariant

> The outer form and form surface **MUST** fill 100% of the canonical LF
> main-content area and **MUST NOT** introduce an additional width cap.

Implementation hiện hành áp dụng trên `admin-form-standard`,
`admin-form-surface`, `admin-form-flow` và selector opt-in
`admin-form-card.admin-form-surface`:

```css
width: 100%;
max-width: none;
min-width: 0;
```

Quy tắc bắt buộc:

* Sidebar expanded: form **MUST** chiếm toàn bộ remaining content area.
* Sidebar collapsed: form **MUST** mở rộng theo content area lớn hơn.
* Physical pixel width **MAY** thay đổi theo available content width.
* Form/card edges **SHOULD** khớp canonical page-content gutters.
* Page header, divider và form content **SHOULD** có effective boundaries nhất
  quán.
* Outer form width và inner field-grid column count là hai khái niệm độc lập.

Form **MUST NOT** dùng:

* `100vw` hoặc `calc(100vw - sidebar-width)`.
* Hard-coded sidebar-width calculation.
* Negative margin để giả lập full width.
* Absolute positioning để tạo width.
* Fixed pixel form width, `fit-content` hoặc arbitrary narrow `max-width`.
* Parent grid giữ một unused right column.
* Global selector thay đổi mọi phần tử `<form>`.

Agent **MUST** kiểm tra computed layout thực tế vì legacy
`.admin-form-card` có thể có width cap; opt-in `admin-form-surface` phải thắng
constraint đó mà không làm thay đổi legacy consumers.

---

# 7. Sidebar And Responsive Matrix

| Context | Outer form | Inner field grid |
| --- | --- | --- |
| Desktop, sidebar expanded | 100% available content width | 1 column |
| Desktop, sidebar collapsed | 100% available content width | 2 columns khi phù hợp |
| Tablet | 100% available content width | 1 column |
| Mobile | 100% available content width | 1 column |

LF sidebar state hiện hành:

* Alpine state: `backendSidebar()` trong `resources/js/app.js`.
* Shell state: `.backend-shell.is-sidebar-collapsed`.
* Early root state chống layout flash:
  `:root.is-backend-sidebar-collapsed` trong
  `resources/views/layouts/backend.blade.php`.
* Persisted key hiện hành: `lf.backend.sidebar.collapsed`.

Field-grid selectors hiện hành:

```css
:root.is-backend-sidebar-collapsed .backend-shell .admin-form-field-grid,
.backend-shell.is-sidebar-collapsed .admin-form-field-grid
```

Collapsed desktop dùng:

```css
grid-template-columns: repeat(2, minmax(0, 1fr));
```

Expanded và tablet/mobile dùng:

```css
grid-template-columns: minmax(0, 1fr);
```

Breakpoint canonical hiện hành để trả collapsed grid về một cột là
`@media (max-width: 900px)`. Mobile footer breakpoint hiện hành là
`@media (max-width: 575.98px)`.

Requirements:

* Agent **MUST** reuse state mechanism trên; **MUST NOT** tạo sidebar store mới.
* Toggle **MUST** reflow form mà không reload.
* Expanded sidebar chỉ đổi inner grid thành một cột; **MUST NOT** cap outer form.
* Full-width fields **MUST** span mọi grid columns.
* Tablet/mobile **MUST** một cột bất kể persisted sidebar state.
* Grid children và complex content **MUST** dùng `min-width: 0` khi cần.
* Long VI/EN labels, helpers, errors **MUST** wrap an toàn.
* Conditional regions đang mở **MUST** reflow theo sidebar state.
* Page **MUST NOT** có horizontal scrolling do form.

---

# 8. Semantic Sections

Sections **MUST** theo business workflow và mỗi section **MUST** sở hữu local
grid của nó. Tên và thứ tự section là module-specific.

Generic section concepts có thể gồm:

```text
Identity
Content
Configuration
Pricing
Availability
Actions
```

Description, rich text, media, long notes, complex selectors và conditional
regions **SHOULD** full width. Empty configuration section **SHOULD** được ẩn
hoặc dùng `admin-form-empty-state` gọn. Hidden content **MUST NOT** reserve
space.

Hai unrelated sections **MUST NOT** bị ép vào equal-height columns. Section
heading **MUST** có semantic heading level phù hợp; optional helper dùng
`admin-form-section-help`.

---

# 9. Field Ordering And Grid Rules

* DOM order **MUST** theo natural reading và dependency order.
* Parent/dependency field **MUST** đứng trước dependent field.
* Related short fields **MAY** cùng hàng khi available width đủ.
* Create và Edit **MUST** giữ cùng logical field order.
* CSS visual reordering **MUST NOT** tạo keyboard order sai.
* Label, helper và validation error **MUST** ở cùng field wrapper với control.
* Long hoặc complex field **SHOULD** dùng `admin-form-field--full`.
* Section-local grids **MAY** độc lập chọn field spans; outer width không đổi.

---

# 10. Standard Field Presentation

| Field type | Presentation requirement |
| --- | --- |
| Text, number, currency, date/time | Dùng shared label/control/error classes; không đổi submitted value |
| Select | Giữ native/shared LF control; option text phải translatable và wrap-safe |
| Textarea/rich text | Thường full width; không fixed height ngăn nội dung cần thiết |
| Checkbox/switch | Có accessible name; option panel khi cần title/helper rõ ràng |
| Radio group | Có group label và logical keyboard order |
| Searchable selector | Reuse LF selector/combobox hiện có; không tạo parallel component |
| File/media | Preview và action nằm trong content width; helper/error liên kết control |
| Read-only | Dùng `admin-form-readonly`; phải nhìn rõ là không editable |
| Disabled | Thể hiện unavailable state và giải thích lý do khi người dùng cần biết |
| Conditional | Nằm gần trigger, không reserve space khi ẩn |
| Required marker | Phản ánh validation hiện hành; không tự thêm business requirement |
| Placeholder | Secondary guidance, không thay thế label |
| Helper | Dùng `lf-form-help` hoặc section helper phù hợp |
| Validation error | Dùng `lf-form-error`, gần control, liên kết bằng ARIA khi áp dụng |

User-facing text **MUST NOT** được đặt trong CSS.

---

# 10.1. Description Hierarchy

Presentation **SHOULD** chọn đúng mức độ mô tả:

| Meaning | Canonical presentation |
| --- | --- |
| Neutral input guidance | Muted helper text |
| Auto-generated field | Compact “Automatic” metadata cạnh label |
| Policy restriction | Compact informational notice |
| Consequential warning | Warning callout |
| Validation failure | Error text gần control |
| Non-essential long explanation | Omit hoặc dùng existing help pattern |

Policy notice **MUST NOT** dùng validation-error styling. Metadata mô tả field
**MUST NOT** dùng status badge styling.

## 10.2. Native File Upload Control

File upload trong LF Admin **MUST** giữ native `<input type="file">` để bảo
toàn keyboard access, browser file picker, filename display, `accept`,
validation và submitted file behavior. Presentation nhẹ hơn **MAY** được kích
hoạt bằng shared opt-in class:

```html
<input type="file"
       name="image_file"
       class="lf-form-control admin-file-upload"
       accept="image/*">
```

Canonical implementation nằm tại
`resources/css/admin/admin-components.css` với class
`admin-file-upload`.

Presentation requirements:

* Control **MUST** tiếp tục hiển thị filename hoặc trạng thái chưa chọn file do
  browser cung cấp.
* Button text, button border và filename text **SHOULD** dùng cùng neutral color
  family với LF form control; nút **MUST NOT** trông như primary submit action.
* Button **SHOULD** dùng regular font weight, subtle background và compact
  padding.
* Hover **MAY** tăng contrast vừa phải; focus của toàn control **MUST** tiếp tục
  dùng visible LF focus treatment.
* Upload hint, accepted formats, maximum size và validation error **MUST** nằm
  gần control và dùng shared help/error presentation.
* Styling **MUST NOT** ẩn filename, thay input bằng click handler giả hoặc làm
  giảm native keyboard/file-picker behavior.
* Styling **MUST NOT** thay đổi `type`, `name`, `accept`, `multiple`, `required`,
  validation, controller contract hoặc Media lifecycle.
* Create và Edit của cùng module **MUST** dùng cùng upload presentation.

Browser compatibility requirements:

* Standard `::file-selector-button` và WebKit
  `::-webkit-file-upload-button` **SHOULD** được khai báo thành selector blocks
  riêng. Không gộp chúng nếu một browser có thể invalidate toàn selector list.
* Native button appearance có thể override màu chữ, border hoặc shadow. Một
  scoped override chỉ dưới `.lf-admin-page .admin-file-upload` **MAY** dùng
  explicit neutral values và `!important` khi computed browser evidence cho
  thấy user-agent style vẫn thắng.
* Ngoại lệ `!important` này chỉ dành cho native file-selector pseudo-element;
  nó **MUST NOT** mở rộng sang general form controls.
* Agent **MUST** kiểm tra computed/built CSS và thực hiện hard refresh khi asset
  hash thay đổi trước khi kết luận style chưa áp dụng.

Rollout requirements:

* Đây là opt-in shared presentation. Form upload khác chỉ nhận chuẩn này sau
  khi thêm `admin-file-upload`; **MUST NOT** dùng broad
  `input[type="file"]` selector để thay đổi legacy consumers hàng loạt.
* Khi rollout sang module khác, agent **MUST** giữ nguyên module-specific upload,
  preview, remove, authorization và tenant behavior; chỉ thay class
  presentation.
* Relevant structural/upload tests, production frontend build và
  `git diff --check` **MUST** pass.

---

# 11. Read-Only, Disabled And Hidden

* **Read-only** là persisted/displayed information không được chỉnh tại form.
  Nó **MUST** trông khác editable control nhưng vẫn đọc và focus được khi loại
  control hỗ trợ.
* **Disabled** là control unavailable do state/policy. UI **SHOULD** giải thích
  lý do nếu restriction không hiển nhiên.
* **Hidden** là field không áp dụng cho current state. Nó **MUST NOT** reserve
  layout space.

Disabled visual state **MUST NOT** được xem là backend security. Authorization
và validation phía server vẫn bắt buộc.

---

# 12. Conditional Content And Progressive Disclosure

Trigger và conditional region **MUST** thuộc cùng semantic section. Region
**SHOULD** xuất hiện ngay dưới hoặc gần trigger.

LF pattern hiện hành dùng Alpine state cùng `x-show`/`x-cloak`, ví dụ:

```html
<label class="admin-form-option-panel">
    <input type="checkbox"
           x-model="customMode"
           :aria-expanded="customMode.toString()"
           aria-controls="custom-fields">
    <span>...</span>
</label>

<div id="custom-fields"
     class="admin-form-conditional"
     x-show="customMode"
     x-cloak>
    ...
</div>
```

Requirements:

* Existing values và validation errors **MUST** reopen đúng region.
* Hidden region **MUST NOT** để blank column.
* Frontend migration **MUST NOT** tạo business rule mới.
* Migration **MUST NOT** thay đổi stale-hidden-value semantics hiện hành.
* Toggle **MUST** cập nhật ARIA state khi áp dụng.
* Trigger **MUST** thao tác được bằng keyboard.

---

# 13. Description And Media

* Long descriptions **SHOULD** full width.
* Media preview, iframe, image và video **MUST** nằm trong content width.
* Preview/replace/remove actions **SHOULD** reuse shared action styles.
* Preview controls **MUST** reflow cùng sidebar và viewport.
* File helper và validation **MUST** giữ association với control.
* Media ownership, authorization và lifecycle luôn là module/domain-owned.

Module-specific overflow fixes **MAY** tồn tại khi media component có contract
riêng, nhưng **MUST NOT** được đưa vào generic standard nếu không tái sử dụng.

---

# 14. Pricing And Calculated Summaries

Related price/currency fields **MAY** cùng hàng khi width đủ. Promotion trigger
và dependent fields **SHOULD** dùng progressive disclosure. Calculated hoặc
non-editable value **SHOULD** trình bày như read-only control/summary.

Presentation migration **MUST NOT** thay đổi calculation, precision, currency
rules, field names hoặc submitted values.

---

# 15. Action Footer

Canonical desktop layout:

```text
[Destructive/administrative action]         [Cancel] [Primary action]
```

Desktop requirements:

* `admin-form-footer-danger` ở trái.
* `admin-form-footer-primary` ở phải.
* Trong primary group, Cancel/Back **MUST** đứng trước Primary Submit theo
  logical DOM order và visual order.
* Cancel/Back **MUST** dùng secondary button treatment
  (`btn btn-secondary`) khi đứng cạnh primary submit; **MUST NOT** giảm thành
  text link vì sẽ làm action set thiếu cân bằng.
* Create **MUST** có Cancel và Create/Submit. Edit **MUST** có Cancel và
  Save/Update. Chỉ được thiếu action khi workflow có bằng chứng rõ ràng và
  module documentation cho phép.
* Các nhóm cùng baseline, có divider nhẹ và balanced padding.
* Empty danger group **MUST NOT** làm primary group lệch khỏi phải.

Mobile requirements:

* Primary group xuất hiện trước.
* Danger group ở row riêng.
* Button/link **MUST NOT** overflow và **SHOULD** có touch target phù hợp.

Shared footer chỉ kiểm soát presentation. Module quyết định action nào được
phép, label, route và lifecycle. Archive, Restore, Delete hoặc domain policy
**MUST NOT** nằm trong generic component.

Existing confirmation, loading, disabled và double-submit guard **MUST** được
giữ nguyên. External buttons có thể dùng thuộc tính `form` để submit canonical
form khi cấu trúc module yêu cầu.

---

# 16. Accessibility

Mỗi implementation **MUST** xác nhận:

* Semantic headings và regions hợp lý.
* Label/control association chính xác.
* Required state phản ánh validation.
* Helper/error association qua `aria-describedby` khi áp dụng.
* Logical DOM và focus order ở cả một và hai cột.
* Visible `focus-visible` cho controls và actions.
* Keyboard operation bằng Tab, Enter và Space khi phù hợp.
* Conditional trigger cập nhật `aria-expanded` và `aria-controls` khi áp dụng.
* Disabled state có explanation khi cần.
* Color không phải tín hiệu duy nhất cho status/error.
* Touch targets phù hợp trên mobile.
* Browser zoom 200% không clip content hoặc tạo horizontal scroll.
* Long VI/EN content wrap an toàn.

---

# 17. Internationalization

* User-facing text trong Blade/JavaScript **MUST** dùng LF translations khi
  translation infrastructure áp dụng.
* VI và EN keys **MUST** đầy đủ.
* Placeholder, helper, validation và button labels **MUST** translatable.
* Longer translations **MUST NOT** phá grid hoặc footer.
* CSS **MUST NOT** chứa localized labels.

---

# 18. CSS Implementation Rules

Implementation **MUST** phù hợp
[`LF-Tech-CSS.md`](LF-Tech-CSS.md):

* Reuse shared classes/components.
* Dùng kebab-case, shallow selectors và class-based styling.
* Dùng CSS variables/design tokens hiện có.
* **MUST NOT** dùng inline style.
* **MUST NOT** thêm broad tag selectors cho general form layout.
* **MUST NOT** duplicate shared layout bằng module selector.
* **MUST NOT** dùng fixed width/height cho general form layout.
* **SHOULD NOT** dùng `!important`; mọi ngoại lệ cần evidence.
* Shared change **MUST** backward compatible.
* Nếu shared change có thể phá legacy consumer, dùng opt-in class/modifier.

Course Product là reference implementation đầu tiên, không phải lý do để
hard-code Product selectors vào shared layer.

---

# 19. Business-Logic Preservation

Áp dụng chuẩn **MUST NOT** thay đổi:

* Routes, controllers hoặc services.
* Policies, authorization hoặc tenant isolation.
* Request field names, validation hoặc submitted values.
* Database schema hoặc migrations.
* Lifecycle hoặc ordering.
* Media ownership/lifecycle.
* Version selection hoặc enrollment behavior.
* Pricing calculations.
* Existing concurrency, locking hoặc transaction rules.

Nếu presentation work phát hiện functional defect, agent **MUST** báo riêng và
không sửa trừ khi user cho phép functional fix rõ ràng.

---

# 20. Create/Edit Parity

Create và Edit **SHOULD** dùng shared partial/component nếu kiến trúc hiện hành
hỗ trợ. Hai form **MUST** giữ cùng section order, field order và responsive
behavior.

Khác biệt chỉ **MAY** xuất phát từ state hợp lệ như:

* Persisted values.
* Read-only hoặc disabled state.
* Permissions.
* Lifecycle actions.
* Existing media previews.
* Validation state.

Module **MUST NOT** tạo hai design systems riêng cho Create và Edit.

---

# 21. Required Acceptance Checklist

## Structure

- [ ] Form dùng canonical shared primitives.
- [ ] Sections theo business flow.
- [ ] Không có empty unrelated column.
- [ ] Full-width fields span đúng.
- [ ] Create/Edit dùng cùng structure và logical order.

## Width

- [ ] Form/surface fill main-content container.
- [ ] Computed `max-width` không tạo extra cap.
- [ ] Không có large unused strip bên phải.
- [ ] Form edges khớp page-content gutters.

## Sidebar

- [ ] Expanded: full-width outer form, một field column.
- [ ] Collapsed: full-width outer form, hai columns khi phù hợp.
- [ ] Toggle reflow ngay, không reload.
- [ ] Open conditional regions reflow đúng.

## Responsive

- [ ] Tablet: một column.
- [ ] Mobile: một column.
- [ ] Không horizontal overflow.
- [ ] Browser zoom 200% hoạt động.
- [ ] Long VI/EN content wrap an toàn.

## States

- [ ] Empty/default.
- [ ] Populated.
- [ ] Validation errors.
- [ ] Read-only.
- [ ] Disabled và restriction explanation khi cần.
- [ ] Conditional fields open/closed.
- [ ] Media state khi áp dụng.
- [ ] Native file upload giữ filename, picker, accept/validation và dùng
      `admin-file-upload` khi module opt in.

## Actions

- [ ] Desktop footer alignment đúng.
- [ ] Mobile primary group đứng trước danger group.
- [ ] Cancel đứng trước Primary trong DOM và visual order.
- [ ] Cancel dùng secondary button; Primary dùng primary button.
- [ ] Create/Edit có đủ action bắt buộc theo workflow.
- [ ] Destructive action tách rõ.
- [ ] Loading/double-submit guard được giữ khi đã tồn tại.

## Verification

- [ ] Relevant module và shared-layout tests.
- [ ] Production frontend build nếu có asset/code change.
- [ ] `git diff --check`.
- [ ] Manual browser acceptance khi browser khả dụng.
- [ ] Report deviations và phần chưa thể kiểm tra.

---

# 22. Deviation Policy

Module chỉ **MAY** deviation khi workflow thực sự yêu cầu. Aesthetic preference
không đủ để vi phạm full-width hoặc responsive invariants.

Task report **MUST** nêu:

1. Rule bị deviation.
2. Lý do workflow.
3. Smallest possible deviation.
4. Viewports/states bị ảnh hưởng.
5. Cách width và accessibility invariants vẫn được bảo vệ.

---

# 23. Reference Implementation

Course Product Create/Edit là approved reference implementation đầu tiên:

* Shared CSS: `resources/css/admin/admin-components.css`.
* Admin tokens/layout: `resources/css/admin/admin-layout.css`.
* Product-only CSS: `resources/css/admin/admin-pages.css`.
* Shared Product form partial:
  `resources/views/course-products/partials/form.blade.php`.
* Create view: `resources/views/course-products/create.blade.php`.
* Edit view: `resources/views/course-products/edit.blade.php`.
* Product structural/CSS tests:
  `tests/Feature/CourseProductManagementTest.php`.
* Sidebar/shared layout tests:
  `tests/Feature/BackendLayoutNavigationTest.php`.

Future agents **MUST** inspect current files thay vì giả định paths/classes không
thay đổi. Reference này minh họa presentation; Product-specific business logic
không phải một phần của generic standard.

Existing Category, Template, Lesson, Activity, Teacher, Cohort và Enrollment
forms là compatibility/migration inventory, không mặc nhiên là consumers đã
được migrate.

---

# 24. Quick Use For AI Agents

Khi user nói “Áp dụng thiết kế tiêu chuẩn”:

1. Đọc tài liệu này.
2. Inspect target form và reference implementation.
3. Giữ nguyên business behavior.
4. Áp dụng shared LF form primitives.
5. Verify width/sidebar/responsive matrix.
6. Chạy relevant checks.
7. Report deviations và visual acceptance.

---

End of Document
