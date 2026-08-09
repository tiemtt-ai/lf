# Table: core_course_categories

Version: 1.1

Status: Official Foundation

Last Updated: 2026-06

Document Path: database/course/core_course_categories.md

---

# Purpose

Lưu danh mục phân loại khóa học của từng Tenant.

Cho phép:

* Tổ chức Course Catalog
* Phân nhóm khóa học
* Hỗ trợ tìm kiếm
* Hỗ trợ Navigation
* Hỗ trợ Learning Paths

---

# Examples

```text
Korean

English

Japanese

Programming

Business

Soft Skills
```

---

# Relationships

core_course_categories

1

↓

N

core_course_categories

(parent → children)

---

core_course_categories

1

↓

N

core_course_templates

---

# Business Rules

* Mọi Category phải thuộc customer_id.
* Hỗ trợ Category nhiều cấp.
* Category có thể chứa nhiều Template.
* Category không chứa học viên.
* Category không chứa dữ liệu học tập.
* Category inactive sẽ không hiển thị trên Website.
* Không được xóa Category đang được Template sử dụng.
* Khi tạo mới, server luôn gán `MAX(sort_order) + 1` trong tenant; tenant chưa
  có Category bắt đầu từ `1`. Giá trị client gửi khi Create không quyết định
  thứ tự. Edit vẫn cho phép quản trị thứ tự thủ công.

---

# Category Hierarchy

Ví dụ:

```text
Korean

↓

TOPIK

↓

TOPIK Beginner
```

---

# Fields

## Identity

### id

BIGINT UNSIGNED

Primary key.

### customer_id

BIGINT UNSIGNED

Tenant sở hữu Category.

### parent_id

BIGINT UNSIGNED NULL

Category cha.

NULL = Category gốc.

---

## Basic Information

### name

VARCHAR(255)

Tên danh mục.

### slug

VARCHAR(255)

Slug dùng cho URL.

### description

TEXT NULL

Mô tả danh mục.

---

## Media

### thumbnail_image

VARCHAR(500) NULL

Ảnh đại diện.

### banner_image

VARCHAR(500) NULL

Banner danh mục.

---

## Display

### sort_order

INT DEFAULT 0

Thứ tự hiển thị.

### is_featured

TINYINT(1) DEFAULT 0

Danh mục nổi bật.

---

## SEO

### meta_title

VARCHAR(255) NULL

### meta_description

VARCHAR(500) NULL

### meta_keywords

VARCHAR(500) NULL

---

## Business

### status

VARCHAR(50)

Giá trị:

* active
* inactive

---

## Audit

### created_by

BIGINT UNSIGNED NULL

Người tạo.

### created_at

TIMESTAMP

### updated_at

TIMESTAMP

---

# Indexes

(customer_id)

(customer_id, parent_id)

(customer_id, status)

(customer_id, sort_order)

(customer_id, slug)

---

# Unique Constraints

UNIQUE(customer_id, slug)

---

# Sample Data

## Root Category

```text
id = 1

customer_id = 1

parent_id = NULL

name = Korean

slug = korean

status = active
```

---

## Child Category

```text
id = 2

customer_id = 1

parent_id = 1

name = TOPIK

slug = topik

status = active
```

---

# Notes

Category chỉ chịu trách nhiệm:

* phân loại
* điều hướng
* tổ chức nội dung

Không chịu trách nhiệm:

* Product
* Enrollment
* Progress
* Assessment
* AI Logic

---

End of Document
