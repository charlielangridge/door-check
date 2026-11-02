# Explicit collection permission on adult-child relationships

Record "Authorised to collect" as a boolean on each adult-child relationship, separate from the relationship description. Parents can record an adult, such as an emergency contact, without granting collection permission; staff check the proposed permission during registration approval. A recorded relationship alone will no longer satisfy the relationship requirement for a matched collection request.

This changes the previous rule that any recorded relationship qualified for collection matching. "Authorised to collect" defaults to on for new relationships, and existing relationships retain their current collection permission by starting with the boolean on. Staff can turn it off. Relationships are currently shared across sites; active child status remains independently controlled per site as agreed in ADR 0004.

A relationship whose collection permission is off blocks collection, including the existing staff collection override. Staff must explicitly restore permission on that relationship before allowing the adult to collect the child. This restriction is distinct from the existing override for an adult with no recorded relationship.

When these changes are implemented, update `database/seeders/DemoSeeder.php` alongside the affected schema and workflows so demo data reflects the new fields and agreed registration states. Application and seeder implementation is deferred until the design interview is complete.
