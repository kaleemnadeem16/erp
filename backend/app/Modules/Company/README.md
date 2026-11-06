# Company module

The company's profile and company-wide settings ([ADR 0081](../../../../docs/decisions/0081-central-company-profile-and-company-wide-settings.md)) and its document branding
([ADR 0162](../../../../docs/decisions/0162-company-branding-versions-with-a-sanitised-logo-pinned-into-documents.md), issue #23).

## Owns

- The field registry (`Support/CompanyFields`), `UpdateCompany`, `company_changes` (append-only history; sections GENERAL, LOCALIZATION, REGISTRATION, CONTACT, BRANDING), routes `GET/PATCH /company`, `GET /company-changes`.
- Branding: `company_brand_versions` (immutable, numbered; optional display name, tagline and logo reference to a VERIFIED Files blob with its hash). `Actions/SaveCompanyBranding` appends a version (reason required, unchanged saves write nothing),
  `Actions/ReadCompanyBrand` and `Actions/ReadCompanyBrandLogo` are the typed contract Documents reads (never a company id from the caller). Routes `GET/POST /company/branding` and `GET /company/branding/logo` (private, no-store).
  The logo is PNG or JPEG, 2 MB, 1 megapixel, rebuilt without metadata by Files' `ImageSanitiser`, stored under file owner `company.brand-asset` (`Support/Brand/CompanyBrandFileOwner`).
- `Support/CompanyDutyManifest`: `company.branding.manage` is STAGED, not in the permission vocabulary, granted to nobody; `Support/CompanyAccess` refuses everyone until it is activated by a reviewed manifest change and grant.

## Boundaries

- Documents pins the brand into a manifest at the owner's freeze; Company never reaches into Documents. A document keeps the brand it was frozen with.
- Not here: colours, contact selection, logo placement choices, a brand history screen.
