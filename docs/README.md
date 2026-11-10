# Project documentation

Phase 4 completion was explicitly approved after user testing on 2026-11-10, including administrative login-source management. Phase 3 personnel creation, account connections and the active-source indicator remain included. The current approved system includes Login, Organization, Personnel and Access. Integration progress and verification are recorded in [PLAN.md](../PLAN.md).

## Technical documentation — English

- [Phase 4 login source administration, permissions, database behavior and API contracts](technical/login-source-management.md)

- [Phase 3 personnel creation, account connections, database and API contracts](technical/personnel-account-connections.md)
- [Phase 2 architecture, database changes and business behavior](technical/organization-personnel-access.md)
- [Management API contracts](technical/management-api.md)
- [Login API and browser gateway, including user appearance](technical/login-api.md)
- [Validation evidence and commands](technical/validation.md)
- [Phase 1 infrastructure and Login architecture baseline](technical/architecture.md)
- [Phase 1 identity database baseline](technical/database.md)

## Guides — Persian

- [مدیریت سورس‌های ورود، مجوزها، مبداهای مجاز و بازیابی دسترسی](guides/login-sources.fa.md)

- [ایجاد پرسنل، اتصال حساب‌ها، نشان اتصال فعال و عملیات فاز سوم](guides/personnel.fa.md)
- [شرکت، پرسنل، نقش‌ها، مجوزها و عملیات فاز دوم](guides/organization.fa.md)
- [راه‌اندازی، نگهداری و منابع ورود](guides/development.fa.md)
- [ورود، خروج و ظاهر سامانه](guides/usage.fa.md)

Phase 1 and Phase 2 documents retain their historical scope; Phase 3 supersedes earlier import-only personnel creation, unrestricted same-source membership and missing connection-management contracts. Phase 4 adds source configuration administration and shared CLI safeguards without schema changes; its source-management contracts supersede earlier CLI-only source administration. These documents describe the development system, not a production deployment or a new phase.
