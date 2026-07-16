# LF-Tech-CSS.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LearnForge CSS Architecture

## Overview

Tài liệu này định nghĩa tiêu chuẩn CSS chính thức của LearnForge.

Mục tiêu:

* UI nhất quán
* Dễ bảo trì
* Dễ mở rộng
* Hỗ trợ AI Development
* Hỗ trợ White Label SaaS

---

# CSS Philosophy

LearnForge áp dụng nguyên tắc:

```text
Simple

Reusable

Predictable

Component Based
```

---

# Core Principles

## Principle 1

Không viết CSS theo từng màn hình.

Sai:

```css
.home-page-title {}
.course-page-title {}
.exam-page-title {}
```

---

Đúng:

```css
.page-title {}
```

---

# Principle 2

Ưu tiên component.

---

Ví dụ:

```css
.card {}

.btn {}

.modal {}

.table {}
```

---

# Principle 3

Không lồng CSS quá sâu.

Sai:

```css
.page .wrapper .content .box .title {}
```

---

Đúng:

```css
.content-title {}
```

---

# Principle 4

Ưu tiên class.

Không style bằng:

```css
div {}
span {}
```

---

# CSS Architecture

LearnForge chia CSS thành:

```text
Core

Layout

Components

Pages

Utilities
```

---

# Folder Structure

```text
resources/

└── css/

    ├── core/
    │   ├── variables.css
    │   ├── reset.css
    │   └── typography.css

    ├── layout/
    │   ├── header.css
    │   ├── sidebar.css
    │   └── footer.css

    ├── components/
    │   ├── buttons.css
    │   ├── cards.css
    │   ├── forms.css
    │   ├── tables.css
    │   ├── modal.css
    │   └── badges.css

    ├── pages/
    │   ├── dashboard.css
    │   ├── course.css
    │   └── assessment.css

    └── app.css
```

---

# CSS Naming Convention

LearnForge sử dụng:

```text
kebab-case
```

---

# Examples

Đúng:

```css
.course-card {}

.lesson-item {}

.page-title {}
```

---

Sai:

```css
.courseCard {}

.lessonItem {}

.PageTitle {}
```

---

# Prefix Convention

## Layout

```css
.layout-header {}

.layout-sidebar {}

.layout-content {}
```

---

## Components

```css
.card {}

.btn {}

.modal {}
```

---

## Pages

```css
.course-page {}

.assessment-page {}

.dashboard-page {}
```

---

# CSS Variables

Tất cả màu sắc phải dùng CSS Variables.

---

# File

```css
variables.css
```

---

# Example

```css
:root {

    --lf-primary: #2563eb;

    --lf-success: #16a34a;

    --lf-warning: #f59e0b;

    --lf-danger: #dc2626;

    --lf-text: #111827;

    --lf-border: #e5e7eb;
}
```

---

# Rule

Không hardcode màu.

Sai:

```css
color: blue;
```

---

Đúng:

```css
color: var(--lf-primary);
```

---

# Backend Text Actions

Official rule: all backend text actions must use `color: var(--admin-primary);`.

Mọi text action trong Admin Back Office và Teacher Back Office phải dùng shared
class:

```html
<a class="admin-text-action">Xem</a>

<button class="admin-link-button admin-text-action" type="button">Xóa</button>
```

Shared color rule:

```css
.admin-text-action {
    color: var(--admin-primary);
    text-decoration: none;
}

.admin-text-action:hover,
.admin-text-action:focus,
.admin-text-action:focus-visible {
    text-decoration: underline;
}

.admin-text-action:focus-visible {
    outline: 2px solid var(--admin-primary);
    outline-offset: 2px;
}
```

Hover và keyboard focus phải dùng underline cho cả `<a>` và link-style
`<button>`. Keyboard focus phải có `:focus-visible` indicator rõ ràng và không
được chỉ dựa vào thay đổi màu. Disabled action giữ nguyên disabled behavior và
không nhận interactive underline.

Các action liên quan phải nằm trong shared action container, không chèn ký tự
phân cách hoặc khoảng trắng thủ công:

```css
.admin-table-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}
```

Không dùng `|`, `/`, `•`, `·`, `&nbsp;` hoặc margin theo từng page để phân cách
action. Action group phải wrap trên màn hình hẹp mà không overlap hoặc phá layout.

Rule này áp dụng cho action link và button được trình bày như text link trong
table, card, media area, form và detail page. Breadcrumb, navigation link,
normal content link, filled button, status label, badge và heading không phải
backend text action và không dùng rule này.

Top-level creation action dùng shared primary outlined button:

```html
<a class="btn admin-primary-outline-action">+ Thêm bài học</a>
```

Chỉ action tạo mới ở cấp cao nhất dùng `admin-primary-outline-action`. Record
action và nested action như Xem, Sửa, Xóa, Thêm phần học con, Gắn bài học và
Thêm hoạt động tiếp tục dùng `admin-text-action`.

Class trình bày như `admin-table-action-link` hoặc `admin-link-button` có thể
giữ disabled, typography và layout riêng, nhưng không được định nghĩa màu,
hover/focus feedback cạnh tranh hoặc tạo override theo từng page.

---

# Typography

## Font Strategy

Primary:

```text
Inter
```

---

Fallback:

```text
system-ui
```

---

# Font Sizes

```css
--lf-text-xs

--lf-text-sm

--lf-text-md

--lf-text-lg

--lf-text-xl

--lf-text-2xl
```

---

# Spacing System

LearnForge sử dụng spacing scale.

---

# Example

```css
--lf-space-1: 4px;

--lf-space-2: 8px;

--lf-space-3: 12px;

--lf-space-4: 16px;

--lf-space-5: 20px;

--lf-space-6: 24px;
```

---

# Rule

Không dùng:

```css
margin: 17px;
```

---

Ưu tiên:

```css
margin: var(--lf-space-4);
```

---

# Border Radius

```css
--lf-radius-sm

--lf-radius-md

--lf-radius-lg

--lf-radius-xl
```

---

# Example

```css
--lf-radius-md: 8px;

--lf-radius-lg: 12px;
```

---

# Shadow System

```css
--lf-shadow-sm

--lf-shadow-md

--lf-shadow-lg
```

---

# Example

```css
--lf-shadow-md:
0 4px 12px rgba(0,0,0,.1);
```

---

# Button Standards

## Base Class

```css
.btn {}
```

---

# Variants

```css
.btn-primary {}

.btn-secondary {}

.btn-success {}

.btn-danger {}

.btn-outline {}
```

---

# Rule

Không tạo:

```css
.login-btn

.course-btn

.exam-btn
```

---

# Card Standards

## Base

```css
.card {}
```

---

# Usage

```css
.course-card {}

.teacher-card {}

.student-card {}
```

---

Kế thừa:

```css
.card
```

---

# Form Standards

## Base Classes

```css
.form-group

.form-label

.form-control

.form-error
```

---

# Validation States

```css
.is-valid

.is-invalid
```

---

# Table Standards

## Base

```css
.table {}
```

---

# Variants

```css
.table-striped

.table-hover

.table-responsive
```

---

# Modal Standards

## Base

```css
.modal
```

---

# Sections

```css
.modal-header

.modal-body

.modal-footer
```

---

# Badge Standards

```css
.badge

.badge-success

.badge-warning

.badge-danger
```

---

# Status Colors

## Success

```css
var(--lf-success)
```

---

## Warning

```css
var(--lf-warning)
```

---

## Danger

```css
var(--lf-danger)
```

---

# Responsive Strategy

LearnForge sử dụng:

```text
Desktop First
```

---

# Breakpoints

```css
1200px

992px

768px

576px
```

---

# Example

```css
@media (max-width: 768px) {

}
```

---

# Mobile Rules

## Rule 1

Không tạo giao diện riêng.

Responsive từ desktop.

---

## Rule 2

Table phải hỗ trợ:

```css
.table-responsive
```

---

## Rule 3

Popup phải không tràn màn hình.

---

## Rule 4

Touch Friendly.

---

# Page CSS Rules

## Rule

Page CSS chỉ chứa:

```css
.course-page {}

.assessment-page {}
```

---

Không chứa:

```css
.btn {}

.card {}

.table {}
```

---

# White Label Support

LearnForge hỗ trợ:

```text
Tenant Themes
```

---

# Example

Tenant A

```css
--lf-primary: blue;
```

---

Tenant B

```css
--lf-primary: red;
```

---

# Dark Mode Ready

Future Support

---

# Variables

```css
[data-theme="dark"]
```

---

# AI Development Rules

Mọi AI-generated code phải:

---

# Rule 1

Sử dụng:

```css
.card

.btn

.table

.modal
```

---

# Rule 2

Không hardcode màu.

---

# Rule 3

Không tạo CSS inline.

---

# Rule 4

Không tạo CSS trùng lặp.

---

# CSS Performance Rules

## Avoid

```css
!important
```

---

## Avoid

```css
#id-selector
```

---

## Avoid

```css
div div div div
```

---

## Prefer

```css
.component-class
```

---

# Current Direction

Version 1

```text
Custom CSS

Bootstrap Compatible

Component Based

Desktop First
```

---

# Future Direction

```text
Design Tokens

Theme Engine

Dark Mode

White Label Themes

CSS Build Optimization
```

---

# LF Admin Form Standard

Create/Edit screens may opt in to the shared admin form primitives in
`resources/css/admin/admin-components.css`. Course Product Create/Edit is the
first reference consumer; existing forms are not implicitly migrated.

Minimal structure:

```html
<div class="admin-card admin-form-card admin-form-surface">
    <form class="admin-form-standard">
        <div class="admin-form-flow">
            <section class="admin-form-standard-section">
                <header class="admin-form-section-header">
                    <h2 class="admin-form-section-title">...</h2>
                    <p class="admin-form-section-help">...</p>
                </header>
                <div class="admin-form-field-grid">
                    <div class="admin-form-field">...</div>
                    <div class="admin-form-field admin-form-field--full">...</div>
                </div>
            </section>
        </div>
        <footer class="admin-form-footer">
            <div class="admin-form-footer-danger">...</div>
            <div class="admin-form-footer-primary">...</div>
        </footer>
    </form>
</div>
```

The form, surface and flow always use `width: 100%`, `max-width: none` and
`min-width: 0`. They fill the existing LF main-content container and must not
calculate width from the viewport, add content offsets, or use negative margins.

The field grid is one column while the sidebar is expanded. It becomes two
columns only under the existing `is-backend-sidebar-collapsed` state and returns
to one column at tablet/mobile widths. `admin-form-field--full` spans every
available column. Conditional content uses `admin-form-conditional`, stays out
of layout while hidden, and must keep `min-width: 0` for long content and errors.

`admin-form-option-panel`, `admin-form-readonly`, `admin-form-empty-state`, and
the section header/help classes own reusable presentation only. Modules retain
field names, labels, validation, conditional logic, permissions, and lifecycle
policy. The shared footer aligns administrative/destructive actions left and
the primary group right; on mobile the primary group is presented first and the
danger group moves to its own row. Forms without a danger action leave that
group empty without affecting right alignment.

# Final Statement

CSS trong LearnForge không phải là tập hợp các file style rời rạc.

Nó là một Design System thống nhất.

Mọi giao diện trong:

* Admin Back Office
* Teacher Back Office
* Tenant Website
* Student Personalized Experience
* Public Website

đều phải tuân thủ cùng một chuẩn CSS nhằm đảm bảo:

* tính nhất quán
* khả năng mở rộng
* khả năng bảo trì
* hỗ trợ AI Development

trong dài hạn.

---

End of LF-Tech-CSS
