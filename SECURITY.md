# LearnerTutors API credential security

## Shared Moodle-to-FastAPI key

LearnerTutors uses one installation-wide shared secret. It is not an OpenAI or Gemini provider key.

1. Generate a strong key for FastAPI:
   `python -c "import secrets; print(secrets.token_urlsafe(48))"`
2. Set that value as `RAG_API_KEY` in the FastAPI backend `.env`.
3. Generate a separate 32-byte Moodle encryption key:
   `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;" `
4. Add the generated base64 value to Moodle's `config.php`, after `$CFG` is initialized:

   ```php
   $CFG->local_ragassistant_encryption_key = 'BASE64_32_BYTE_KEY_HERE';
   ```

5. Restart/reload the PHP web server so the configuration is applied.
6. In Site administration → Plugins → Local plugins → Course Learning Assistant, enter the same FastAPI `RAG_API_KEY` in Application API key and save.
7. Open Site administration → Plugins → Local plugins → Test FastAPI connection and run the test.

The Moodle encryption key must be different from `RAG_API_KEY`. Keep it out of source control, database backups, and web-accessible files. Back it up securely: losing or changing it makes previously encrypted API keys unreadable. If it is lost, generate a new encryption key and re-enter the shared FastAPI key.

## Storage and transport

- Moodle stores the application key as AES-256-GCM ciphertext in `config_plugins`.
- A fresh random nonce and an authentication tag are used for each encryption.
- The encryption master key is held in `config.php` or the PHP process environment, not in Moodle's database.
- The settings form renders an empty password field; it never sends the saved key back to the administrator's browser. Leaving it empty preserves the current key.
- Existing plaintext `api_key` settings are encrypted automatically on the first authenticated backend request after the master key is configured.
- Moodle sends the decrypted key only from PHP to FastAPI in the `X-RAG-API-Key` header.
- Moodle requires HTTPS for non-loopback backend URLs and keeps TLS certificate verification enabled. HTTP is permitted only for localhost development.
- FastAPI rejects missing/short backend secrets and compares supplied keys using constant-time comparison.

## Rotation

To rotate the shared key, generate a new backend `RAG_API_KEY`, update the backend environment and restart FastAPI, then enter the matching new value in Moodle. There may be a brief authentication interruption between these steps. For zero-downtime rotation, a future enhancement would support a short-lived previous key during rollout.

## Operational limits

Encryption at rest protects against a database-only disclosure. It does not protect against a full Moodle host compromise, malicious PHP code, or an administrator with server-level access. Restrict access to `config.php`, secure backups, use HTTPS, and keep the backend network-restricted.

## Dynamic OpenAI and Gemini provider credentials

The OpenAI and Gemini keys are separate from the shared `RAG_API_KEY`.

1. Set a dedicated backend encryption key once in the FastAPI `.env`:
   `RUNTIME_CONFIG_ENCRYPTION_KEY=<Fernet key>`
2. Generate it with:
   `python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"`
3. Install the updated backend dependencies (`pip install -r requirements.txt`).
4. In Moodle Site administration → Plugins → Local plugins → Course Learning Assistant, enter the OpenAI API key and/or Google Gemini API key and save.
5. The next chat request or document indexing request synchronizes changed settings to FastAPI. The backend encrypts provider credentials before saving them in `storage/runtime_config.json`; no FastAPI restart is required.
6. The saved Moodle fields remain blank in the browser. Entering a new value rotates that provider credential; leaving the field blank keeps the current value.

The backend runtime encryption key must remain stable and must be backed up securely. If it is lost or changed, previously synchronized provider keys cannot be decrypted. Re-enter the provider keys in Moodle after restoring or replacing the encryption key.

Provider keys are sent only in an authenticated Moodle PHP-to-FastAPI request over the configured backend connection. For non-localhost backend URLs, Moodle requires HTTPS. The backend config read endpoint returns only configured/not-configured booleans, never provider key values.
