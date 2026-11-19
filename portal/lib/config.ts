/** Server-side settings, read from .env.local. Never import this from a client component. */
export const API_BASE_URL = (process.env.API_BASE_URL ?? 'http://127.0.0.1:8000/api/v1').replace(/\/+$/, '');

/** httpOnly cookie that holds the Laravel Sanctum token. JavaScript in the browser cannot read it. */
export const SESSION_COOKIE = 'al_portal_token';

export const SESSION_COOKIE_SECURE = process.env.SESSION_COOKIE_SECURE === 'true';

/** Name of the token in the user's "signed-in devices" list. */
export const DEVICE_NAME = 'Admin website';

/** Display timezone and currency used by the API for reports. */
export const DISPLAY_TIMEZONE = 'Australia/Sydney';
export const BASE_CURRENCY = 'AUD';
