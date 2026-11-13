import axios from 'axios';

const client = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

// A 401 on any call except sign-in means the session ended: tell the app so it can show the login page.
client.interceptors.response.use(
  (response) => response,
  (error) => {
    const url = error.config?.url || '';

    if (error.response?.status === 401 && !url.includes('/auth/login') && !url.includes('/auth/me')) {
      window.dispatchEvent(new Event('auth:expired'));
    }

    return Promise.reject(error);
  },
);

/** Laravel Sanctum needs this cookie before the first state-changing request. */
export function ensureCsrfCookie() {
  return client.get('/sanctum/csrf-cookie');
}

export default client;
