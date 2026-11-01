# Member registration

- Sign In links to `/register`. Applicants provide Name, Email, active Designation, active Unit, Mobile Number, Password and Confirm Password.
- Mobile is exactly 11 ASCII digits, stored as text so leading zeroes remain. It is required publicly and optional in backend user create/edit.
- Registration creates a separate pending application, not a sign-in account. Incoming role/activation fields are ignored. Passwords are hashed, excluded from serialization/logs, and cleared from the application after approval.
- Administrator and Librarian can review requests through Dashboard, Administration and Users, then approve after manually verifying the displayed information. Approval creates an active Library Member using the submitted password. There is no automatic email or SMS notification; applicants sign in after approval.
- Approval locks the application, prevents repeat approval, revalidates designation/unit, and refuses emails already used by live or deleted users. Request and approval events are audited. Public submissions are limited to five per minute per IP.
- Existing users remain unchanged. Administratively created users do not require registration approval. Profile updates remain limited to name/email; mobile is managed in Users.
