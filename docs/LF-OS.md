# LF-OS.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

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

* Course
* Lesson
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
