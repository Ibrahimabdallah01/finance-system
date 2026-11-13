/** A short message a person can act on, from any failed request. */
export function getErrorMessage(error) {
  if (!error) return 'Something went wrong.';

  if (error.response) {
    const { status, data } = error.response;

    if (status === 422) {
      const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
      return first || data?.message || 'Please check the form and try again.';
    }
    if (status === 401) return 'Your session has ended. Please sign in again.';
    if (status === 403) return 'You are not allowed to do that.';
    if (status === 404) return 'That item no longer exists.';
    if (status === 419) return 'Your session expired. Reload the page and try again.';
    if (status === 429) return 'Too many attempts. Please wait a moment and try again.';
    if (status >= 500) return 'The server had a problem. Nothing was saved. Please try again.';

    return data?.message || 'The request failed.';
  }

  if (error.request) return 'Cannot reach the server. Check that the API is running and try again.';

  return error.message || 'Something went wrong.';
}

/** { field: "first message" } from a 422 response. Nested names like units.0.imei are kept as written. */
export function getFieldErrors(error) {
  const errors = error?.response?.status === 422 ? error.response.data?.errors : null;

  if (!errors) return {};

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, [].concat(messages)[0]]));
}
