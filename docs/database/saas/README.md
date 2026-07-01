# MIỀN NGHIỆP VỤ SAAS TENANT

Miền nghiệp vụ SaaS Tenant là nền tảng đa tenant của LearnForge. Miền nghiệp vụ
này xác định từng Khách hàng, phân giải miền của yêu cầu, quản lý cấu hình
tenant và tư cách thành viên, kiểm soát vòng đời của lời mời, đồng thời lưu giữ
lịch sử kiểm tra cấp tenant trên một nền tảng dùng chung với trải nghiệm Khách
hàng được cô lập.

---

## Tổng quan Miền nghiệp vụ

- **Trách nhiệm nghiệp vụ:** Thiết lập định danh Khách hàng và ranh giới tenant
  được mọi miền nghiệp vụ phía sau sử dụng.
- **Phạm vi:** Vòng đời Khách hàng, định tuyến miền, thiết lập, tư cách thành
  viên, lời mời và kiểm tra tenant.
- **Sở hữu:** Định danh Khách hàng, cấu hình tenant, ánh xạ miền, tư cách thành
  viên giữa Người dùng và Khách hàng, lời mời và lịch sử kiểm tra tenant.
- **Không sở hữu:** Định danh xác thực, trạng thái học tập, kết quả Đánh giá,
  xử lý Media, đầu ra AI, Đăng ký dịch vụ, Mức sử dụng, Hóa đơn hoặc Thanh toán.
- **Miền nghiệp vụ liên quan:** Xác thực, Người dùng, Thương mại, Mức sử dụng,
  Thanh toán và mọi miền nghiệp vụ LearnForge có phạm vi tenant.

---

## Các nhóm cơ sở dữ liệu

## 1. Định danh Khách hàng

| Bảng | Mô tả |
|------|------|
| saas_customers | Định danh gốc và vòng đời của Khách hàng |

---

## 2. Phân giải và cấu hình tenant

| Bảng | Mô tả |
|------|------|
| saas_customer_domains | Danh mục đăng ký chuẩn cho miền của yêu cầu |
| saas_customer_settings | Các thiết lập cấu hình tenant |

---

## 3. Tư cách thành viên và lời mời

| Bảng | Mô tả |
|------|------|
| saas_customer_members | Tư cách thành viên giữa Người dùng và Khách hàng |
| saas_customer_invitations | Vòng đời lời mời vào tenant |

---

## 4. Kiểm tra tenant

| Bảng | Mô tả |
|------|------|
| saas_audit_logs | Lịch sử kiểm tra tenant chỉ ghi nối tiếp |

---

## Sơ đồ quan hệ Miền nghiệp vụ

```mermaid
erDiagram
    CUSTOMER ||--o{ CUSTOMER_DOMAIN : resolves
    CUSTOMER ||--o{ CUSTOMER_SETTING : configures
    CUSTOMER ||--o{ CUSTOMER_MEMBER : includes
    CUSTOMER ||--o{ CUSTOMER_INVITATION : invites
    CUSTOMER ||--o{ AUDIT_LOG : audits
    USER ||--o{ CUSTOMER_MEMBER : joins
    USER ||--o{ CUSTOMER_INVITATION : accepts
```

---

## Luồng nghiệp vụ

```mermaid
flowchart LR
    A[Request Host] --> B[Customer Domain Registry]
    B --> C[Customer]
    C --> D[Tenant Context]
    D --> E[Authentication]
    E --> F[Active Membership]
    F --> G[Authorized Role Experience]
    G --> H[Tenant Audit]
```

---

## Quan hệ liên Miền nghiệp vụ

Tenant → Xác thực để thực hiện xác thực nhận biết tenant  
Tenant → Người dùng để tham chiếu định danh và hồ sơ  
Tenant → Khóa học, Đánh giá, LiveClass, Media, Theo dõi và AI để bảo đảm cô lập  
Tenant → Thương mại để cung cấp ngữ cảnh Đăng ký dịch vụ của Khách hàng  
Tenant → Mức sử dụng để xác định quyền sở hữu phép đo theo Khách hàng  
Tenant → Thanh toán để xác định quyền sở hữu tài chính theo Khách hàng

---

## Nguyên tắc thiết kế

- Khách hàng là gốc của quyền sở hữu tenant.
- Tenant được phân giải trước khi xác thực.
- Hoạt động định tuyến miền sử dụng Danh mục đăng ký miền chuẩn.
- Định danh Người dùng và tư cách thành viên của Khách hàng là hai trách nhiệm
  riêng biệt.
- Tư cách thành viên là Nguồn dữ liệu chuẩn cho quan hệ Người dùng–Khách hàng.
- Lời mời có vòng đời bảo mật và rõ ràng.
- Thiết lập tenant không thay thế trạng thái có ràng buộc của miền nghiệp vụ.
- Lịch sử kiểm tra là bằng chứng chỉ ghi nối tiếp.
- Không tenant nào được truy cập dữ liệu nghiệp vụ của tenant khác.
- Ngữ cảnh tenant không chuyển quyền sở hữu dữ liệu trạng thái nghiệp vụ phía
  sau.
