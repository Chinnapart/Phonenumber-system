/**
 * ConnectPro API Client
 * File: frontend/assets/js/core/api.js
 *
 * Responsibilities:
 * - Centralize requests to the PHP backend
 * - Send PHP session cookies with every request
 * - Handle JSON, FormData, Blob, timeout, and cancellation
 * - Normalize API errors
 * - Redirect when a session expires or access is denied
 *
 * Security:
 * - Authentication tokens are not stored in localStorage
 * - PHP session authentication uses secure HttpOnly cookies
 * - CSRF tokens are read from a meta element, cookie, or runtime config
 */

"use strict";

const DEFAULT_CONFIG = Object.freeze({
    apiBaseUrl: "/connectpro/backend/api",
    timeout: 15000,
    credentials: "include",
    csrfHeaderName: "X-CSRF-Token",
    csrfCookieName: "connectpro_csrf_token",
    csrfMetaName: "csrf-token",
    sessionExpiredUrl:
        "/connectpro/frontend/pages/auth/session-expired.html",
    accessDeniedUrl:
        "/connectpro/frontend/pages/auth/access-denied.html",
    loginUrl: "/connectpro/frontend/pages/auth/login.html",
    debug: false
});

const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"]);
const requestInterceptors = [];
const responseInterceptors = [];
const pendingRequests = new Map();

/**
 * Error returned by the ConnectPro backend or network layer.
 */
export class ApiError extends Error {
    constructor(message, options = {}) {
        super(message || "เกิดข้อผิดพลาดระหว่างเชื่อมต่อระบบ");

        this.name = "ApiError";
        this.status = Number(options.status || 0);
        this.code = options.code || "API_ERROR";
        this.details = options.details ?? null;
        this.requestId = options.requestId || null;
        this.url = options.url || null;
        this.method = options.method || null;
        this.isNetworkError = Boolean(options.isNetworkError);
        this.isTimeout = Boolean(options.isTimeout);
        this.isCancelled = Boolean(options.isCancelled);
        this.cause = options.cause;
    }
}

/**
 * Change API configuration at runtime.
 * Call this once before the first API request when custom paths are required.
 */
export function configureApi(overrides = {}) {
    if (!isPlainObject(overrides)) {
        throw new TypeError("API configuration must be an object.");
    }

    window.CONNECTPRO_CONFIG = {
        ...(window.CONNECTPRO_CONFIG || {}),
        ...overrides
    };

    return getConfig();
}

/**
 * Add an interceptor that can modify a request before fetch executes.
 * The interceptor must return the request context or nothing.
 */
export function addRequestInterceptor(interceptor) {
    assertFunction(interceptor, "Request interceptor");
    requestInterceptors.push(interceptor);

    return () => removeInterceptor(requestInterceptors, interceptor);
}

/**
 * Add an interceptor that can inspect or transform a successful response.
 */
export function addResponseInterceptor(interceptor) {
    assertFunction(interceptor, "Response interceptor");
    responseInterceptors.push(interceptor);

    return () => removeInterceptor(responseInterceptors, interceptor);
}

/**
 * Execute an HTTP request.
 */
export async function request(endpoint, options = {}) {
    const config = getConfig();
    const method = String(options.method || "GET").toUpperCase();
    const url = buildUrl(endpoint, options.query, config.apiBaseUrl);
    const timeout = normalizeTimeout(options.timeout, config.timeout);
    const controller = new AbortController();
    const requestKey = options.requestKey || null;

    if (requestKey && options.cancelPrevious !== false) {
        cancelRequest(requestKey);
        pendingRequests.set(requestKey, controller);
    }

    const cleanupExternalSignal = connectAbortSignal(
        options.signal,
        controller
    );

    const timeoutId = window.setTimeout(() => {
        controller.abort(createAbortReason("timeout"));
    }, timeout);

    let context = {
        url,
        method,
        headers: createHeaders(method, options, config),
        body: createBody(options.body),
        signal: controller.signal,
        credentials: options.credentials || config.credentials,
        cache: options.cache || "no-store",
        redirect: options.redirect || "follow",
        responseType: options.responseType || "auto"
    };

    try {
        context = await runRequestInterceptors(context);
        debugLog(config, "request", sanitizeContext(context));

        const response = await fetch(context.url, {
            method: context.method,
            headers: context.headers,
            body: SAFE_METHODS.has(context.method) ? undefined : context.body,
            signal: context.signal,
            credentials: context.credentials,
            cache: context.cache,
            redirect: context.redirect
        });

        const payload = await parseResponse(response, context.responseType);

        if (!response.ok) {
            throw createResponseError(response, payload, context);
        }

        const normalized = normalizeSuccessPayload(
            payload,
            response,
            options.returnMeta === true
        );

        const intercepted = await runResponseInterceptors(
            normalized,
            response,
            context
        );

        debugLog(config, "response", {
            status: response.status,
            url: context.url
        });

        return intercepted;
    } catch (error) {
        const apiError = normalizeThrownError(error, context, controller);
        debugLog(config, "error", apiError);

        if (options.handleAuthError !== false) {
            handleAuthenticationError(apiError, config);
        }

        throw apiError;
    } finally {
        window.clearTimeout(timeoutId);
        cleanupExternalSignal();

        if (requestKey && pendingRequests.get(requestKey) === controller) {
            pendingRequests.delete(requestKey);
        }
    }
}

export function get(endpoint, options = {}) {
    return request(endpoint, { ...options, method: "GET" });
}

export function post(endpoint, body, options = {}) {
    return request(endpoint, { ...options, method: "POST", body });
}

export function put(endpoint, body, options = {}) {
    return request(endpoint, { ...options, method: "PUT", body });
}

export function patch(endpoint, body, options = {}) {
    return request(endpoint, { ...options, method: "PATCH", body });
}

export function remove(endpoint, options = {}) {
    return request(endpoint, { ...options, method: "DELETE" });
}

export function upload(endpoint, formData, options = {}) {
    if (!(formData instanceof FormData)) {
        throw new TypeError("upload() requires a FormData instance.");
    }

    return request(endpoint, {
        ...options,
        method: options.method || "POST",
        body: formData
    });
}

export function download(endpoint, options = {}) {
    return request(endpoint, {
        ...options,
        method: options.method || "GET",
        responseType: "blob"
    });
}

/**
 * Cancel a pending request registered with requestKey.
 */
export function cancelRequest(requestKey) {
    const controller = pendingRequests.get(requestKey);

    if (!controller) {
        return false;
    }

    controller.abort(createAbortReason("cancelled"));
    pendingRequests.delete(requestKey);
    return true;
}

export function cancelAllRequests() {
    for (const [requestKey, controller] of pendingRequests.entries()) {
        controller.abort(createAbortReason("cancelled"));
        pendingRequests.delete(requestKey);
    }
}

function getConfig() {
    const runtimeConfig = window.CONNECTPRO_CONFIG || {};

    return {
        ...DEFAULT_CONFIG,
        ...runtimeConfig,
        apiBaseUrl: trimTrailingSlash(
            runtimeConfig.apiBaseUrl || DEFAULT_CONFIG.apiBaseUrl
        )
    };
}

function buildUrl(endpoint, query, apiBaseUrl) {
    if (typeof endpoint !== "string" || endpoint.trim() === "") {
        throw new TypeError("API endpoint must be a non-empty string.");
    }

    const isAbsolute = /^https?:\/\//i.test(endpoint);
    const normalizedEndpoint = endpoint.trim();
    const rawUrl = isAbsolute
        ? normalizedEndpoint
        : `${apiBaseUrl}/${normalizedEndpoint.replace(/^\/+/, "")}`;
    const url = new URL(rawUrl, window.location.origin);

    appendQueryParams(url.searchParams, query);
    return url.toString();
}

function appendQueryParams(searchParams, query) {
    if (!query) {
        return;
    }

    if (!isPlainObject(query) && !(query instanceof URLSearchParams)) {
        throw new TypeError("Query parameters must be an object.");
    }

    const entries = query instanceof URLSearchParams
        ? query.entries()
        : Object.entries(query);

    for (const [key, value] of entries) {
        if (value === undefined || value === null || value === "") {
            continue;
        }

        if (Array.isArray(value)) {
            for (const item of value) {
                searchParams.append(key, String(item));
            }
            continue;
        }

        if (value instanceof Date) {
            searchParams.set(key, value.toISOString());
            continue;
        }

        searchParams.set(key, String(value));
    }
}

function createHeaders(method, options, config) {
    const headers = new Headers({
        Accept: "application/json"
    });

    if (!(options.body instanceof FormData) && options.body !== undefined) {
        headers.set("Content-Type", "application/json; charset=UTF-8");
    }

    if (!SAFE_METHODS.has(method)) {
        const csrfToken = getCsrfToken(config);

        if (csrfToken) {
            headers.set(config.csrfHeaderName, csrfToken);
        }
    }

    for (const [name, value] of new Headers(options.headers || {})) {
        headers.set(name, value);
    }

    return headers;
}

function createBody(body) {
    if (body === undefined || body === null) {
        return undefined;
    }

    if (
        body instanceof FormData ||
        body instanceof Blob ||
        body instanceof URLSearchParams ||
        typeof body === "string"
    ) {
        return body;
    }

    return JSON.stringify(body);
}

function getCsrfToken(config) {
    const runtimeToken = window.CONNECTPRO_CONFIG?.csrfToken;

    if (typeof runtimeToken === "string" && runtimeToken.trim()) {
        return runtimeToken.trim();
    }

    const metaToken = document
        .querySelector(`meta[name="${config.csrfMetaName}"]`)
        ?.getAttribute("content");

    if (metaToken) {
        return metaToken;
    }

    return readCookie(config.csrfCookieName);
}

async function parseResponse(response, responseType) {
    if (response.status === 204 || response.status === 205) {
        return null;
    }

    if (responseType === "blob") {
        return response.blob();
    }

    if (responseType === "text") {
        return response.text();
    }

    const contentType = response.headers.get("content-type") || "";

    if (responseType === "json" || contentType.includes("application/json")) {
        const text = await response.text();

        if (!text) {
            return null;
        }

        try {
            return JSON.parse(text);
        } catch (error) {
            throw new ApiError("รูปแบบข้อมูลตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง", {
                status: response.status,
                code: "INVALID_JSON_RESPONSE",
                details: text.slice(0, 500),
                cause: error
            });
        }
    }

    return response.text();
}

function normalizeSuccessPayload(payload, response, returnMeta) {
    if (returnMeta) {
        return {
            data: extractData(payload),
            message: payload?.message || null,
            meta: payload?.meta || null,
            status: response.status,
            headers: response.headers
        };
    }

    return extractData(payload);
}

function extractData(payload) {
    if (
        isPlainObject(payload) &&
        Object.prototype.hasOwnProperty.call(payload, "data")
    ) {
        return payload.data;
    }

    return payload;
}

function createResponseError(response, payload, context) {
    const fallbackMessages = {
        400: "ข้อมูลที่ส่งไปไม่ถูกต้อง",
        401: "Session หมดอายุ กรุณาเข้าสู่ระบบใหม่",
        403: "บัญชีนี้ไม่มีสิทธิ์ดำเนินการ",
        404: "ไม่พบข้อมูลหรือบริการที่ร้องขอ",
        409: "ข้อมูลขัดแย้งกับข้อมูลปัจจุบันในระบบ",
        422: "กรุณาตรวจสอบข้อมูลที่กรอก",
        429: "มีการเรียกใช้งานบ่อยเกินไป กรุณารอสักครู่",
        500: "เซิร์ฟเวอร์เกิดข้อผิดพลาด กรุณาลองใหม่ภายหลัง",
        503: "ระบบไม่พร้อมให้บริการชั่วคราว"
    };

    return new ApiError(
        payload?.message ||
            fallbackMessages[response.status] ||
            `ไม่สามารถดำเนินการได้ (HTTP ${response.status})`,
        {
            status: response.status,
            code: payload?.code || `HTTP_${response.status}`,
            details: payload?.errors || payload?.details || payload || null,
            requestId:
                payload?.requestId ||
                response.headers.get("x-request-id") ||
                null,
            url: context.url,
            method: context.method
        }
    );
}

function normalizeThrownError(error, context, controller) {
    if (error instanceof ApiError) {
        error.url ||= context.url;
        error.method ||= context.method;
        return error;
    }

    if (error?.name === "AbortError" || controller.signal.aborted) {
        const reason = controller.signal.reason;
        const isTimeout = reason?.type === "timeout";

        return new ApiError(
            isTimeout
                ? "การเชื่อมต่อใช้เวลานานเกินกำหนด กรุณาลองใหม่"
                : "ยกเลิกคำขอแล้ว",
            {
                code: isTimeout ? "REQUEST_TIMEOUT" : "REQUEST_CANCELLED",
                isTimeout,
                isCancelled: !isTimeout,
                url: context.url,
                method: context.method,
                cause: error
            }
        );
    }

    if (error instanceof TypeError) {
        return new ApiError(
            "ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ กรุณาตรวจสอบเครือข่าย",
            {
                code: "NETWORK_ERROR",
                isNetworkError: true,
                url: context.url,
                method: context.method,
                cause: error
            }
        );
    }

    return new ApiError(error?.message, {
        code: "UNEXPECTED_ERROR",
        url: context.url,
        method: context.method,
        cause: error
    });
}

function handleAuthenticationError(error, config) {
    if (error.status === 401) {
        safeRedirect(config.sessionExpiredUrl);
        return;
    }

    if (error.status === 403) {
        safeRedirect(`${config.accessDeniedUrl}?reason=forbidden`);
    }
}

function safeRedirect(path) {
    if (!path || window.location.pathname === new URL(path, location.origin).pathname) {
        return;
    }

    window.location.assign(path);
}

async function runRequestInterceptors(initialContext) {
    let context = initialContext;

    for (const interceptor of requestInterceptors) {
        const result = await interceptor(context);
        context = result || context;
    }

    return context;
}

async function runResponseInterceptors(payload, response, context) {
    let result = payload;

    for (const interceptor of responseInterceptors) {
        const intercepted = await interceptor(result, response, context);
        result = intercepted === undefined ? result : intercepted;
    }

    return result;
}

function connectAbortSignal(externalSignal, controller) {
    if (!externalSignal) {
        return () => {};
    }

    if (externalSignal.aborted) {
        controller.abort(externalSignal.reason);
        return () => {};
    }

    const abortHandler = () => controller.abort(externalSignal.reason);
    externalSignal.addEventListener("abort", abortHandler, { once: true });

    return () => externalSignal.removeEventListener("abort", abortHandler);
}

function createAbortReason(type) {
    if (typeof DOMException === "function") {
        const reason = new DOMException(
            type === "timeout" ? "Request timeout" : "Request cancelled",
            "AbortError"
        );
        reason.type = type;
        return reason;
    }

    return { type };
}

function normalizeTimeout(value, fallback) {
    const timeout = Number(value ?? fallback);

    if (!Number.isFinite(timeout) || timeout < 1) {
        return fallback;
    }

    return timeout;
}

function readCookie(name) {
    const encodedName = encodeURIComponent(name);
    const cookie = document.cookie
        .split("; ")
        .find((item) => item.startsWith(`${encodedName}=`));

    if (!cookie) {
        return null;
    }

    return decodeURIComponent(cookie.slice(cookie.indexOf("=") + 1));
}

function removeInterceptor(collection, interceptor) {
    const index = collection.indexOf(interceptor);

    if (index >= 0) {
        collection.splice(index, 1);
        return true;
    }

    return false;
}

function assertFunction(value, label) {
    if (typeof value !== "function") {
        throw new TypeError(`${label} must be a function.`);
    }
}

function isPlainObject(value) {
    return (
        value !== null &&
        typeof value === "object" &&
        Object.getPrototypeOf(value) === Object.prototype
    );
}

function trimTrailingSlash(value) {
    return String(value).replace(/\/+$/, "");
}

function sanitizeContext(context) {
    return {
        url: context.url,
        method: context.method,
        responseType: context.responseType
    };
}

function debugLog(config, phase, value) {
    if (!config.debug) {
        return;
    }

    console.debug(`[ConnectPro API:${phase}]`, value);
}

const api = Object.freeze({
    configure: configureApi,
    request,
    get,
    post,
    put,
    patch,
    delete: remove,
    upload,
    download,
    cancel: cancelRequest,
    cancelAll: cancelAllRequests,
    addRequestInterceptor,
    addResponseInterceptor
});

export default api;
