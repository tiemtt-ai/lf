# LF-OS.md

Version: 1.0

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: LF-OS.md

---

# LearnForge Operating System (LF-OS)

LF-OS là tập hợp các nguyên tắc cốt lõi định hướng toàn bộ quá trình phát triển LearnForge.

LF-OS không mô tả:

* Database
* API
* Source Code
* AWS
* Framework

Những nội dung đó thuộc LF-Core và LF-SaaS.

LF-OS trả lời câu hỏi:

"Tại sao LearnForge được thiết kế như hiện tại?"

và

"LearnForge nên phát triển theo hướng nào trong tương lai?"

---

# Mission

LearnForge hướng tới việc trở thành:

AI-Native Learning Intelligence Platform

thay vì chỉ là một LMS truyền thống.

Mục tiêu không chỉ là:

* quản lý khóa học
* quản lý học viên
* quản lý bài thi

Mà còn phải:

* hiểu người học
* hỗ trợ người học
* cá nhân hóa việc học
* nâng cao hiệu quả học tập

---

# Vision

LearnForge giúp:

* Trường học
* Trung tâm đào tạo
* Giáo viên
* Doanh nghiệp đào tạo

xây dựng hệ sinh thái học tập số trên một nền tảng thống nhất.

Trong dài hạn:

LearnForge không chỉ là LMS.

LearnForge là:

Learning Intelligence Platform

---

# Core Principles

Mọi quyết định thiết kế trong LearnForge phải ưu tiên các nguyên tắc sau.

---

# Principle 1

AI Native

AI không phải tính năng bổ sung.

AI là thành phần nền tảng của hệ thống.

Sai:

LMS
+
AI chatbot

Đúng:

Learning Platform
+
Tracking
+
Knowledge
+
AI Intelligence
+
Personalization

Mọi module trong LearnForge phải được thiết kế với khả năng AI tích hợp từ đầu.

---

# Principle 2

Track Before AI

AI không thể thông minh nếu không có dữ liệu.

Do đó:

Track

↓

Analyze

↓

Insight

↓

Personalization

↓

Learning Improvement

là chuỗi giá trị cốt lõi của LearnForge.

Nếu không có tracking:

* không có analytics
* không có personalization
* không có AI insight

---

# Principle 3

Customer-Centric Data Ownership

Mọi dữ liệu đều thuộc về khách hàng.

Khách hàng có quyền sở hữu:

* khóa học
* bài học
* học viên
* dữ liệu học tập
* dữ liệu AI

LearnForge chỉ cung cấp nền tảng vận hành.

---

# Principle 4

Everything Belongs To A Customer

Trong hệ thống:

customer_id

là trung tâm của toàn bộ kiến trúc.

Mọi dữ liệu nghiệp vụ đều phải xác định được:

* thuộc tenant nào
* thuộc khách hàng nào

Điều này áp dụng cho:

* Course Template
* Course Product
* Template Lesson
* Quiz
* Media
* Tracking
* AI
* Billing

---

# Principle 5

Shared Infrastructure First

Mặc định:

LearnForge vận hành theo mô hình SaaS dùng chung.

Bao gồm:

* AWS dùng chung
* Database dùng chung
* Redis dùng chung
* Storage dùng chung
* AI Provider dùng chung

Điều này giúp:

* giảm chi phí
* triển khai nhanh
* dễ mở rộng

cho phần lớn khách hàng.

---

# Principle 6

Bring Your Own Infrastructure Ready

Dù mặc định là Shared SaaS,

LearnForge phải luôn sẵn sàng hỗ trợ:

BYOC

Bring Your Own Cloud

Ví dụ:

* AWS Account riêng
* S3 riêng
* CloudFront riêng

Enterprise customer có thể sử dụng hạ tầng riêng mà không cần thay đổi kiến trúc nền tảng.

---

# Principle 7

Bring Your Own AI Key Ready

LearnForge không được phụ thuộc vào một nhà cung cấp AI duy nhất.

Khách hàng có thể:

* dùng OpenAI Key của LearnForge
* dùng OpenAI Key riêng
* dùng Claude Key riêng
* dùng Gemini Key riêng

LearnForge phải hỗ trợ:

BYOK

Bring Your Own Key

ngay từ định hướng kiến trúc.

---

# Principle 8

Customer Owns Infrastructure Choices

Khách hàng có quyền lựa chọn:

* Shared AWS
* Dedicated AWS
* Shared AI Key
* Dedicated AI Key

LearnForge không ép buộc một mô hình triển khai duy nhất.

---

# Principle 9

LearnForge Owns Platform Intelligence

Khách hàng có thể sở hữu:

* dữ liệu
* cloud
* storage
* AI key

Nhưng LearnForge vẫn sở hữu:

* workflow
* orchestration
* analytics
* personalization
* tracking engine
* AI intelligence

Đây là lợi thế cạnh tranh cốt lõi của nền tảng.

---

# Principle 10

Simplicity First

Ưu tiên giải pháp đơn giản nhất có thể.

Không xây dựng:

* microservice quá sớm
* abstraction không cần thiết
* kiến trúc phức tạp vượt nhu cầu thực tế

Nguyên tắc:

Simple Before Complex

---

# Principle 11

Monolith First

Ở giai đoạn hiện tại:

Laravel Monolith là kiến trúc mặc định.

Lợi ích:

* phát triển nhanh
* dễ bảo trì
* dễ onboarding
* giảm chi phí vận hành

Chỉ tách dịch vụ khi có dữ liệu thực tế chứng minh cần thiết.

---

# Principle 12

Async First

Các tác vụ nặng nên xử lý bất đồng bộ.

Ví dụ:

* AI Processing
* Transcript Generation
* OCR
* Video Processing
* Email
* Notification
* Analytics

Thông qua:

* Queue
* Redis
* Worker

---

# Principle 13

Enterprise Ready

Ngay từ đầu hệ thống phải chuẩn bị cho:

* Multi Tenant
* Quota
* Billing
* Usage Tracking
* AI Usage Tracking
* Dedicated Infrastructure

dù chưa triển khai đầy đủ.

---

# Principle 14

Measure Everything

Nếu không đo lường được thì không thể tối ưu được.

LearnForge phải lưu đủ dữ liệu cho:

* Billing
* Analytics
* AI
* Monitoring
* Reporting

Nhưng không lưu dữ liệu dư thừa.

---

# Principle 15

Long-Term Scalability

Mọi quyết định kỹ thuật phải cân bằng giữa:

* tốc độ phát triển hiện tại
* khả năng mở rộng tương lai

Không hy sinh tương lai.

Nhưng cũng không over-engineering ở hiện tại.

---

# Principle 16

Simple User Experience, Deep Learning Data

Người dùng thao tác trên LearnForge phải thật đơn giản.

Điều này áp dụng cho:

* Tenant / Customer Admin
* Teacher
* Student

LearnForge không ép người dùng hiểu cấu trúc kỹ thuật phức tạp như:

* database
* media pipeline
* tracking model
* AI context
* billing logic
* system workflow

Người dùng chỉ nên nhìn thấy những thao tác rõ ràng, tự nhiên và đúng vai trò.

Tenant users such as Customer Admin and Teacher must not be exposed to
technical or system fields directly.

The following implementation fields must not appear as raw editable inputs on
Admin or Teacher forms:

* metadata
* JSON config
* snapshot payload
* system payload
* internal flags
* AI context
* source snapshots
* implementation/internal fields

Users must never be required to edit raw JSON.

If a technical configuration needs to be controlled by users, expose it as a
clear business field or UI control.

Examples:

```text
Featured
Display on homepage
Badge label
Sort order
Visibility
```

The UI must stay simple. Deep/system data can exist behind the scenes.

Media interaction follows the same rule.

Users should see media controls in business language:

```text
Ảnh đại diện
Ảnh hoặc video giới thiệu
Tài liệu bài học
Video bài học
```

Users must not be exposed to storage keys, buckets, disks, raw metadata JSON,
or internal media lifecycle fields.

Media preview must feel like part of the product experience, not a storage
browser. Images and videos should open in a standard preview modal or popup,
not in raw browser tabs or public storage URLs.

Media lists and forms must stay lightweight. Video should not be embedded as
inline players in rows, cards, or edit forms. The interface should show a
thumbnail, poster, icon, or placeholder and load video only after the user
chooses Preview.

Removing media from a business object is a business relationship change. It
detaches the usage mapping and must not delete the underlying Media File unless
the Media lifecycle explicitly allows deletion.

Ví dụ:

Customer Admin:

```text
Tạo khóa học
Gán giáo viên
Add học viên
Xem báo cáo
```

Teacher:

```text
Tạo bài học
Gắn video
Tạo quiz
Chấm bài
Theo dõi học viên
```

Student:

```text
Vào khóa học
Xem bài học
Làm bài tập
Hỏi AI Tutor
Xem tiến độ
```

Nhưng bên dưới giao diện đơn giản đó, hệ thống phải ghi nhận và tổ chức dữ liệu thật đầy đủ.

LearnForge phải lưu đủ dữ liệu cho:

* Course Structure
* Lesson Activity
* Media Usage
* Learning Progress
* Assessment Result
* User Behavior
* Tracking Events
* AI Context
* Usage Analytics
* Billing Inputs

Nguyên tắc cốt lõi:

```text
Simple UX

↓

Deep Data

↓

AI Ready
```

Người dùng thao tác càng đơn giản càng tốt.

Nhưng dữ liệu phía sau phải đủ sâu để phục vụ:

* Tracking
* Analytics
* AI Tutor
* AI Grading
* Personalization
* Learning Insights
* Risk Detection
* Billing
* Enterprise Reporting

LearnForge phải tách rõ:

```text
User Experience
```

và

```text
System Intelligence
```

User Experience phải đơn giản.

System Intelligence phải triệt để.

Đây là nguyên tắc quan trọng giúp LearnForge vừa dễ dùng cho người vận hành, vừa đủ mạnh để trở thành AI-Native Learning Intelligence Platform.

---

# Principle 17

One Website Experience

Student không bị chuyển từ Website A sang Portal B sau khi đăng nhập.

Flow đúng:

```text
Website Tenant

↓

Login

↓

Personalized Website Tenant
```

Nguyên tắc:

```text
One Tenant

One Website

Multiple Experiences
```

Public Experience và Student Experience cùng tồn tại trên một Tenant Website.

Admin và Teacher sử dụng Back Office.

Student sử dụng Tenant Website.

---

# User Interface Governance

LearnForge phải cung cấp trải nghiệm nhất quán trên nhiều loại thiết bị.

Mọi thay đổi liên quan đến:

* UI
* Layout
* CSS
* Responsive Design
* Navigation
* Forms
* Tables
* Cards
* Modals

đều phải được kiểm tra trên nhiều kích thước màn hình.

---

# Responsive First

Responsive không phải bước kiểm tra cuối cùng.

Responsive phải được xem là yêu cầu mặc định trong quá trình thiết kế và phát triển giao diện.

Mọi Experience của LearnForge phải hoạt động ổn định trên:

* Mobile
* Tablet
* Laptop
* Desktop

---

# Standard Breakpoints

Required:

```text
375px   Mobile Small

430px   Mobile Large

768px   Tablet Portrait

1024px  Tablet Landscape

1366px  Laptop

1440px  Desktop
```

Optional:

```text
1920px  Large Desktop
```

---

# Responsive Validation Scope

Mọi thay đổi giao diện phải được kiểm tra cho các Experience hiện có:

```text
Public Experience

Student Experience

Authentication Experience

Admin Experience

Teacher Experience
```

---

# Required Components

Tối thiểu phải xác nhận:

* Header
* Navigation
* Forms
* Tables
* Cards
* Modals
* Footer
* Language Switcher

---

# Invalid UI States

Không được xuất hiện:

* Horizontal Overflow
* Broken Layout
* Hidden Navigation
* Unusable Forms
* Modal Overflow
* Text Truncation gây mất chức năng
* Content vượt viewport
* Navigation bị che khuất

---

# Responsive QA Requirement

Mọi thay đổi liên quan tới:

* CSS
* Layout
* Navigation
* UI Components

phải thực hiện Responsive QA trước khi hoàn thành.

QA tối thiểu phải báo cáo:

* Breakpoints Tested
* Experiences Tested
* Issues Found
* Fixes Applied

---

# Minimum Verification

Tối thiểu phải kiểm tra:

```text
375px
768px
1366px
1440px
```

trước khi xác nhận hoàn thành thay đổi giao diện.

---

# Core Rule

```text
Build Once

Test Everywhere
```

Đây là quy tắc phát triển giao diện bắt buộc áp dụng cho toàn bộ LearnForge.

Mọi AI Agent, Developer và Contributor của LearnForge phải tuân thủ quy tắc này khi thay đổi giao diện người dùng.

---

# LearnForge Intelligence Loop

Đây là vòng lặp cốt lõi của toàn bộ nền tảng.

Learning

↓

Tracking

↓

AI Intelligence

↓

Insight

↓

Personalization

↓

Better Learning Experience

↓

More Learning Data

↓

Tracking

↓

AI Intelligence

...

Đây là lợi thế cạnh tranh dài hạn của LearnForge.

---

# LearnForge Architecture Language

Tầng kiến trúc:

# LF-Core

Technical Foundation

# LF-SaaS

Business Foundation

# LF-OS

System Philosophy

Tầng dữ liệu:

# core_

Learning Core

# saas_

Business SaaS

# media_

Media Layer

# track_

Learning Analytics

# ai_

AI Intelligence

---

# Final Statement

LearnForge không hướng tới việc trở thành một LMS lớn hơn.

LearnForge hướng tới việc trở thành:

AI-Native Learning Intelligence Platform

nơi dữ liệu học tập được chuyển hóa thành trí tuệ,
và trí tuệ được chuyển hóa thành trải nghiệm học tập tốt hơn cho mỗi người học.

---

End of LF-OS
