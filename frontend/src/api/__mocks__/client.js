// Test double for the API client. Tests describe the answers with mockApi() in src/test/utils.jsx.
import { vi } from 'vitest';

const client = {
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
};

export const ensureCsrfCookie = vi.fn(() => Promise.resolve({}));
export default client;
