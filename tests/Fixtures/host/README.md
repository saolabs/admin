# Isolated host fixture

This test-only host document, context configuration and language catalog are derived from the SaoLabs starter at the admin alpha integration point. They are committed here so package tests need no sibling checkout. Tests compile the actual package frontend and render it with this host document.

The installer does not copy these files into a consumer. Production applications supply their own document/auth/Gate/query scope. The disposable user data and fake operator are workbench fixtures, never production bootstrap credentials.
