/**
 * ConnectPro Authentication Manager
 * File: frontend/assets/js/core/auth.js
 * Dependency: ./api.js
 *
 * Responsibilities:
 * - Login and logout through PHP Session
 * - Load and cache the current authenticated user in memory
 * - Guard pages by authentication status and role
 * - Apply role-based UI visibility
 * - Coordinate session expiration across browser tabs
 *
 * Important:
 * Frontend role checks improve UX only. Every PHP API endpoint must validate
 * the session, account status, role, permission, and CSRF token again.
 */

"use strict";

import api, { ApiError } from "./api.js";

const AUTH_CONFIG = Object.freeze({
    endpoints: Object.freeze({
        login: "auth/login.php",
        logout: "auth/logout.php",
        session: "auth/session.php",
        csrf: "auth/csrf.php"
    }),
    pages: Object.freeze({
        login: "/connectpro/frontend/pages/auth/login.html",
        sessionExpired:
            "/connectpro/frontend/pages/auth/session-expired.html",
        accessDenied:
            "/connectpro/frontend/pages/auth/access-denied.html",
        adminDashboard:
            "/connectpro/frontend/pages/admin/dashboard.html",
        userDashboard:
            "/connectpro/frontend/pages/user/dashboard.html"
    }),
    roles: Object.freeze({
        ADMIN: "admin",
        USER: "user"
    }),
    accountStatuses: Object.freeze({
        ACTIVE: "active",
        INACTIVE: "inactive",
        LOCKED: "locked",
        SUSPENDED: "suspended"
    }),
    sessionRefreshInterval: 5 * 60 * 1000,
    sessionCheckOnFocus: true,
    channelName: "connectpro-auth"
});

const AUTH_EVENTS = Object.freeze({
    CHANGED: "connectpro:auth-changed",
    LOGIN: "connectpro:login",
    LOGOUT: "connectpro:logout",
    SESSION_EXPIRED: "connectpro:session-expired",
    ACCESS_DENIED: "connectpro:access-denied"
});

let currentUser = null;
let sessionLoaded = false;
let sessionPromise = null;
let sessionTimerId = null;
let initialized = false;
let authChannel = null;

/**
 * Merge runtime authentication settings with safe defaults.
 */
function getConfig() {
    const runtime = window.CONNECTPRO_AUTH_CONFIG || {};

    return {
        ...AUTH_CONFIG,
        ...runtime,
        endpoints: {
            ...AUTH_CONFIG.endpoints,
            ...(runtime.endpoints || {})
        },
        pages: {
            ...AUTH_CONFIG.pages,
            ...(runtime.pages || {})
        },
        roles: {
            ...AUTH_CONFIG.roles,
            ...(runtime.roles || {})
        },
        accountStatuses: {
            ...AUTH_CONFIG.accountStatuses,
            ...(runtime.accountStatuses || {})
        }
    };
}

/**
 * Configure authentication paths before calling initAuth().
 */
export function configureAuth(overrides = {}) {
    if (!isPlainObject(overrides)) {
        throw new TypeError("Authentication configuration must be an object.");
    }

    window.CONNECTPRO_AUTH_CONFIG = {
        ...(window.CONNECTPRO_AUTH_CONFIG || {}),
        ...overrides,
        endpoints: {
            ...(window.CONNECTPRO_AUTH_CONFIG?.endpoints || {}),
            ...(overrides.endpoints || {})
        },
        pages: {
            ...(window.CONNECTPRO_AUTH_CONFIG?.pages || {}),
            ...(overrides.pages || {})
        }
    };

    return getConfig();
}

/**
 * Initialize cross-tab events and optional session refresh.
 */
export function initAuth(options = {}) {
    if (initialized) {
        return;
    }

    initialized = true;
    setupCrossTabChannel();

    const config = getConfig();
    const refreshSession = options.refreshSession !== false;

    if (refreshSession && config.sessionRefreshInterval > 0) {
        startSessionRefresh(config.sessionRefreshInterval);
    }

    if (config.sessionCheckOnFocus) {
        window.addEventListener("focus", handleWindowFocus);
    }
}

/**
 * Authenticate a user through the backend.
 */
export async function login(credentials, options = {}) {
    const payload = normalizeCredentials(credentials);
    const config = getConfig();

    const result = await api.post(config.endpoints.login, payload, {
        requestKey: "auth-login",
        cancelPrevious: true,
        handleAuthError: false
    });

    const user = normalizeUser(result?.user || result);
    assertActiveUser(user, config);

    setCurrentUser(user, "login");
    broadcastAuthEvent("login");

    if (options.redirect !== false) {
        redirectByRole(user, options.redirectTo);
    }

    return user;
}

/**
 * End the PHP session and clear all in-memory authentication state.
 */
export async function logout(options = {}) {
    const config = getConfig();
    let logoutError = null;

    try {
        await api.post(config.endpoints.logout, {}, {
            requestKey: "auth-logout",
            handleAuthError: false
        });
    } catch (error) {
        logoutError = error;
    } finally {
        clearCurrentUser("logout");
        api.cancelAll();
        broadcastAuthEvent("logout");
    }

    if (options.redirect !== false) {
        safeRedirect(options.redirectTo || config.pages.login);
    }

    if (logoutError && options.throwOnError === true) {
        throw logoutError;
    }

    return true;
}

/**
 * Fetch the current PHP session user.
 * Concurrent calls share the same request promise.
 */
export async function fetchCurrentUser(options = {}) {
    if (sessionLoaded && !options.force) {
        return currentUser;
    }

    if (sessionPromise && !options.force) {
        return sessionPromise;
    }

    const config = getConfig();

    sessionPromise = api
        .get(config.endpoints.session, {
            requestKey: "auth-session",
            cancelPrevious: options.force === true,
            handleAuthError: false
        })
        .then((result) => {
            const user = normalizeUser(result?.user || result);
            assertActiveUser(user, config);
            setCurrentUser(user, "session");
            return user;
        })
        .catch((error) => {
            clearCurrentUser("session-error");

            if (error instanceof ApiError && error.status === 401) {
                return null;
            }

            throw error;
        })
        .finally(() => {
            sessionPromise = null;
        });

    return sessionPromise;
}

/**
 * Return the cached user without making a network request.
 */
export function getCurrentUser() {
    return currentUser ? { ...currentUser } : null;
}

export function isAuthenticated() {
    return Boolean(currentUser?.id && currentUser?.role);
}

export function hasRole(...roles) {
    if (!isAuthenticated()) {
        return false;
    }

    const acceptedRoles = flattenValues(roles).map(normalizeRole);
    return acceptedRoles.includes(normalizeRole(currentUser.role));
}

export function hasPermission(...permissions) {
    if (!isAuthenticated()) {
        return false;
    }

    const userPermissions = new Set(
        (currentUser.permissions || []).map(normalizePermission)
    );

    return flattenValues(permissions)
        .map(normalizePermission)
        .every((permission) => userPermissions.has(permission));
}

export function isAdmin() {
    return hasRole(getConfig().roles.ADMIN);
}

/**
 * Guard a protected page. Returns the current user when access is permitted.
 */
export async function requireAuth(options = {}) {
    const config = getConfig();
    const user = await fetchCurrentUser({ force: options.force === true });

    if (!user) {
        dispatchAuthEvent(AUTH_EVENTS.SESSION_EXPIRED, null);

        if (options.redirect !== false) {
            const returnUrl = encodeURIComponent(getSafeReturnUrl());
            safeRedirect(
                `${options.loginUrl || config.pages.login}?returnUrl=${returnUrl}`
            );
        }

        return null;
    }

    const allowedRoles = normalizeAllowedRoles(options.roles);

    if (allowedRoles.length > 0 && !allowedRoles.includes(user.role)) {
        dispatchAuthEvent(AUTH_EVENTS.ACCESS_DENIED, { user });

        if (options.redirect !== false) {
            safeRedirect(
                options.accessDeniedUrl ||
                    `${config.pages.accessDenied}?reason=role`
            );
        }

        return null;
    }

    applyRoleVisibility(document, user);
    hydrateUserElements(document, user);
    return user;
}

/**
 * Redirect an authenticated user away from login and other guest-only pages.
 */
export async function requireGuest(options = {}) {
    const user = await fetchCurrentUser();

    if (!user) {
        return true;
    }

    if (options.redirect !== false) {
        redirectByRole(user, options.redirectTo);
    }

    return false;
}

/**
 * Show or hide elements carrying role and permission attributes.
 * Supported attributes:
 * data-auth-only
 * data-guest-only
 * data-role="admin,user"
 * data-permission="contacts.create,contacts.update"
 */
export function applyRoleVisibility(root = document, user = currentUser) {
    const authenticated = Boolean(user?.id && user?.role);

    root.querySelectorAll("[data-auth-only]").forEach((element) => {
        setElementAvailable(element, authenticated);
    });

    root.querySelectorAll("[data-guest-only]").forEach((element) => {
        setElementAvailable(element, !authenticated);
    });

    root.querySelectorAll("[data-role]").forEach((element) => {
        const roles = splitAttribute(element.dataset.role).map(normalizeRole);
        const allowed = authenticated && roles.includes(user.role);
        setElementAvailable(element, allowed);
    });

    root.querySelectorAll("[data-permission]").forEach((element) => {
        const required = splitAttribute(element.dataset.permission);
        const allowed =
            authenticated &&
            required.every((permission) =>
                (user.permissions || []).includes(normalizePermission(permission))
            );
        setElementAvailable(element, allowed);
    });
}

/**
 * Fill common user placeholders without page-specific JavaScript.
 * Supported attributes:
 * data-user-field="display_name"
 * data-user-initials
 */
export function hydrateUserElements(root = document, user = currentUser) {
    if (!user) {
        return;
    }

    root.querySelectorAll("[data-user-field]").forEach((element) => {
        const fieldName = element.dataset.userField;
        element.textContent = getNestedValue(user, fieldName) ?? "-";
    });

    root.querySelectorAll("[data-user-initials]").forEach((element) => {
        element.textContent = getInitials(user.displayName || user.username);
    });
}

/**
 * Obtain a CSRF token from the backend and expose it to api.js.
 */
export async function refreshCsrfToken() {
    const config = getConfig();
    const result = await api.get(config.endpoints.csrf, {
        requestKey: "auth-csrf",
        handleAuthError: false
    });
    const token = result?.csrfToken || result?.token;

    if (typeof token !== "string" || !token.trim()) {
        throw new ApiError("เซิร์ฟเวอร์ไม่ได้ส่ง CSRF Token ที่ถูกต้อง", {
            code: "INVALID_CSRF_TOKEN"
        });
    }

    api.configure({ csrfToken: token.trim() });
    return token.trim();
}

export function redirectByRole(user = currentUser, explicitUrl = null) {
    const config = getConfig();

    if (explicitUrl) {
        safeRedirect(explicitUrl);
        return;
    }

    if (normalizeRole(user?.role) === normalizeRole(config.roles.ADMIN)) {
        safeRedirect(config.pages.adminDashboard);
        return;
    }

    safeRedirect(config.pages.userDashboard);
}

export function startSessionRefresh(interval = getConfig().sessionRefreshInterval) {
    stopSessionRefresh();

    const refreshInterval = Number(interval);

    if (!Number.isFinite(refreshInterval) || refreshInterval < 30000) {
        throw new RangeError("Session refresh interval must be at least 30 seconds.");
    }

    sessionTimerId = window.setInterval(async () => {
        if (document.visibilityState !== "visible") {
            return;
        }

        try {
            const user = await fetchCurrentUser({ force: true });

            if (!user) {
                handleExpiredSession();
            }
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                handleExpiredSession();
            }
        }
    }, refreshInterval);
}

export function stopSessionRefresh() {
    if (sessionTimerId !== null) {
        window.clearInterval(sessionTimerId);
        sessionTimerId = null;
    }
}

export function destroyAuth() {
    stopSessionRefresh();
    window.removeEventListener("focus", handleWindowFocus);
    authChannel?.close();
    authChannel = null;
    initialized = false;
}

function normalizeCredentials(credentials) {
    if (!isPlainObject(credentials)) {
        throw new TypeError("Login credentials must be an object.");
    }

    const username = String(credentials.username || "").trim();
    const password = String(credentials.password || "");

    if (!username) {
        throw new ApiError("กรุณากรอกชื่อผู้ใช้งาน", {
            status: 422,
            code: "USERNAME_REQUIRED",
            details: { username: ["กรุณากรอกชื่อผู้ใช้งาน"] }
        });
    }

    if (!password) {
        throw new ApiError("กรุณากรอกรหัสผ่าน", {
            status: 422,
            code: "PASSWORD_REQUIRED",
            details: { password: ["กรุณากรอกรหัสผ่าน"] }
        });
    }

    return {
        username,
        password,
        remember: Boolean(credentials.remember)
    };
}

function normalizeUser(rawUser) {
    if (!rawUser || typeof rawUser !== "object") {
        return null;
    }

    const role = normalizeRole(
        rawUser.role ?? rawUser.role_name ?? rawUser.roleName
    );
    const permissions = Array.isArray(rawUser.permissions)
        ? rawUser.permissions.map(normalizePermission)
        : [];

    return Object.freeze({
        id: Number(rawUser.id || rawUser.user_id || 0),
        username: String(rawUser.username || ""),
        displayName: String(
            rawUser.display_name || rawUser.displayName || rawUser.username || ""
        ),
        employeeCode: String(
            rawUser.employee_code || rawUser.employeeCode || ""
        ),
        role,
        accountStatus: normalizeStatus(
            rawUser.account_status || rawUser.accountStatus || "active"
        ),
        departmentId: normalizeNullableNumber(
            rawUser.department_id || rawUser.departmentId
        ),
        departmentName: String(
            rawUser.department_name || rawUser.departmentName || ""
        ),
        permissions,
        lastLoginAt: rawUser.last_login_at || rawUser.lastLoginAt || null
    });
}

function assertActiveUser(user, config) {
    if (!user?.id || !user?.role) {
        throw new ApiError("ข้อมูล Session ไม่สมบูรณ์", {
            status: 401,
            code: "INVALID_SESSION_USER"
        });
    }

    if (user.accountStatus !== config.accountStatuses.ACTIVE) {
        throw new ApiError("บัญชีผู้ใช้งานไม่อยู่ในสถานะ Active", {
            status: 403,
            code: "ACCOUNT_NOT_ACTIVE",
            details: { accountStatus: user.accountStatus }
        });
    }
}

function setCurrentUser(user, source) {
    currentUser = user;
    sessionLoaded = true;
    dispatchAuthEvent(AUTH_EVENTS.CHANGED, { user, source });
}

function clearCurrentUser(source) {
    currentUser = null;
    sessionLoaded = true;
    sessionPromise = null;
    dispatchAuthEvent(AUTH_EVENTS.CHANGED, { user: null, source });
}

function handleExpiredSession() {
    const config = getConfig();
    clearCurrentUser("expired");
    broadcastAuthEvent("session-expired");
    dispatchAuthEvent(AUTH_EVENTS.SESSION_EXPIRED, null);
    safeRedirect(config.pages.sessionExpired);
}

async function handleWindowFocus() {
    if (!sessionLoaded || !currentUser) {
        return;
    }

    try {
        const user = await fetchCurrentUser({ force: true });

        if (!user) {
            handleExpiredSession();
        }
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            handleExpiredSession();
        }
    }
}

function setupCrossTabChannel() {
    if (!("BroadcastChannel" in window)) {
        return;
    }

    authChannel = new BroadcastChannel(getConfig().channelName);
    authChannel.addEventListener("message", (event) => {
        const type = event.data?.type;

        if (type === "logout") {
            clearCurrentUser("cross-tab-logout");
            safeRedirect(getConfig().pages.login);
        }

        if (type === "session-expired") {
            clearCurrentUser("cross-tab-expired");
            safeRedirect(getConfig().pages.sessionExpired);
        }

        if (type === "login") {
            sessionLoaded = false;
            sessionPromise = null;
        }
    });
}

function broadcastAuthEvent(type) {
    authChannel?.postMessage({ type, timestamp: Date.now() });
}

function dispatchAuthEvent(eventName, detail) {
    window.dispatchEvent(new CustomEvent(eventName, { detail }));
}

function setElementAvailable(element, available) {
    element.hidden = !available;
    element.setAttribute("aria-hidden", String(!available));

    if ("disabled" in element) {
        element.disabled = !available;
    }
}

function normalizeAllowedRoles(roles) {
    return flattenValues(roles || []).map(normalizeRole).filter(Boolean);
}

function normalizeRole(value) {
    return String(value || "").trim().toLowerCase();
}

function normalizePermission(value) {
    return String(value || "").trim().toLowerCase();
}

function normalizeStatus(value) {
    return String(value || "").trim().toLowerCase();
}

function normalizeNullableNumber(value) {
    if (value === undefined || value === null || value === "") {
        return null;
    }

    const result = Number(value);
    return Number.isFinite(result) ? result : null;
}

function splitAttribute(value) {
    return String(value || "")
        .split(",")
        .map((item) => item.trim())
        .filter(Boolean);
}

function flattenValues(values) {
    return values.flat(Infinity).filter((value) => value !== undefined);
}

function getNestedValue(object, path) {
    return String(path || "")
        .split(".")
        .filter(Boolean)
        .reduce((value, key) => value?.[key], object);
}

function getInitials(value) {
    const words = String(value || "")
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (words.length === 0) {
        return "?";
    }

    return words
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join("");
}

function getSafeReturnUrl() {
    return `${window.location.pathname}${window.location.search}`;
}

function safeRedirect(path) {
    if (!path) {
        return;
    }

    const target = new URL(path, window.location.origin);

    if (target.origin !== window.location.origin) {
        throw new Error("Cross-origin authentication redirect is not allowed.");
    }

    const current = `${window.location.pathname}${window.location.search}`;
    const destination = `${target.pathname}${target.search}`;

    if (current !== destination) {
        window.location.assign(destination);
    }
}

function isPlainObject(value) {
    return (
        value !== null &&
        typeof value === "object" &&
        Object.getPrototypeOf(value) === Object.prototype
    );
}

const auth = Object.freeze({
    configure: configureAuth,
    init: initAuth,
    login,
    logout,
    fetchCurrentUser,
    getCurrentUser,
    isAuthenticated,
    isAdmin,
    hasRole,
    hasPermission,
    requireAuth,
    requireGuest,
    applyRoleVisibility,
    hydrateUserElements,
    refreshCsrfToken,
    redirectByRole,
    startSessionRefresh,
    stopSessionRefresh,
    destroy: destroyAuth,
    events: AUTH_EVENTS
});

export { AUTH_EVENTS };
export default auth;
