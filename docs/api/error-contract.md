# API Error Contract

All browser-facing application errors use one safe JSON envelope:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The submitted data is invalid.",
    "errors": {
      "email": ["The email field is required."]
    },
    "request_id": "01J..."
  }
}
```

## Required Fields

- `code` is a stable uppercase machine code. UI behavior must not depend on the human message.
- `message` is safe for display and contains no exception details, SQL, paths, secrets, or hidden fields.
- `errors` is always an object. Validation keys use the backend field path; non-validation responses use an empty object.
- `request_id` matches the `X-Request-ID` response header and may be shared with support staff.

## Status Mapping

| HTTP status | Baseline code | Client behavior |
|---|---|---|
| 400 | `BAD_REQUEST` | Explain that the request could not be understood. |
| 401 | `UNAUTHENTICATED` | Clear the bearer token through the single token module and route to sign-in. |
| 403 | `FORBIDDEN` | Keep the user signed in and show an authorization state. |
| 404 | `NOT_FOUND` | Show the feature's not-found state. |
| 405 | `METHOD_NOT_ALLOWED` | Treat as a contract/configuration error. |
| 409 | Context-specific conflict code | Preserve valid input and explain the state conflict. |
| 422 | `VALIDATION_FAILED` | Map entries in `errors` to matching React Hook Form fields. |
| 429 | `THROTTLED` | Respect `Retry-After` and provide a meaningful retry state. |
| 5xx | `INTERNAL_ERROR` or dependency-specific safe code | Keep input when applicable and offer a safe retry. |

Context-specific business codes will be added only with their complete PRD vertical slice and tests.

## Correlation IDs

- Clients may send `X-Request-ID` using 1–128 ASCII letters, digits, dots, underscores, or hyphens.
- Invalid caller values are ignored and replaced with a generated ID.
- Every API response includes the accepted/generated ID.
- IDs may be logged as operational context but must never encode personal data, tokens, or secrets.
