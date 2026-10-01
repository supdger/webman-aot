## ADDED Requirements

### Requirement: Correct publisher identity
The Composer distribution MUST use supdger/webman-aot-builder and Supdger\WebmanAotInstaller consistently and MUST retain SaiAdmin business compatibility separately.

#### Scenario: New install identity
- **WHEN** Composer installs the new package
- **THEN** autoload, help, owner marker and distribution metadata identify supdger and bridge0.3.5/runtime0.3.2

### Requirement: Explicit historical migration
The new setup MUST NOT adopt a historical owner. Uninstall MUST accept only the two exact package identities and schema1, recheck after acquiring the setup lock, and remove only the selected item.

#### Scenario: Old state cleanup
- **WHEN** an old saiadmin schema1 state is discovered
- **THEN** it is labeled with that exact package, retained by default, and removed only after explicit confirmation while retaining the lock inode

#### Scenario: Both packages share global home
- **WHEN** both exact packages are required in one global home
- **THEN** both are listed independently and confirming only the old package preserves the new package and other tools

#### Scenario: Cleaned state reuse
- **WHEN** old payload and owner are explicitly removed and the lock remains
- **THEN** new setup may claim the empty managed payload without replacing the lock or adopting old runtime
