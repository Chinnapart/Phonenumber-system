/**
 * ConnectPro Core Utilities
 * File: frontend/assets/js/core/utils.js
 * Dependency: none
 */

"use strict";

const DEFAULT_LOCALE = "th-TH";
const DEFAULT_TIME_ZONE = "Asia/Bangkok";

/* --------------------------------------------------------------------------
   Type and Value Helpers
   -------------------------------------------------------------------------- */

export function isPlainObject(value) {
    return value !== null &&
        typeof value === "object" &&
        Object.getPrototypeOf(value) === Object.prototype;
}

export function isEmpty(value) {
    if (value === null || value === undefined) return true;
    if (typeof value === "string") return value.trim() === "";
    if (Array.isArray(value)) return value.length === 0;
    if (value instanceof Map || value instanceof Set) return value.size === 0;
    if (isPlainObject(value)) return Object.keys(value).length === 0;
    return false;
}

export function toNumber(value, fallback = 0) {
    if (value === null || value === undefined || value === "") return fallback;
    const result = Number(value);
    return Number.isFinite(result) ? result : fallback;
}

export function toInteger(value, fallback = 0) {
    const result = Number.parseInt(value, 10);
    return Number.isFinite(result) ? result : fallback;
}

export function toBoolean(value, fallback = false) {
    if (typeof value === "boolean") return value;
    if (typeof value === "number") return value !== 0;

    const normalized = String(value ?? "").trim().toLowerCase();
    if (["true", "1", "yes", "on"].includes(normalized)) return true;
    if (["false", "0", "no", "off"].includes(normalized)) return false;
    return fallback;
}

export function clamp(value, min, max) {
    const number = toNumber(value);
    return Math.min(Math.max(number, min), max);
}

export function unique(values = []) {
    return [...new Set(values)];
}

export function compact(values = []) {
    return values.filter((value) => value !== null && value !== undefined && value !== "");
}

export function chunk(values = [], size = 10) {
    const length = Math.max(1, toInteger(size, 10));
    const result = [];

    for (let index = 0; index < values.length; index += length) {
        result.push(values.slice(index, index + length));
    }

    return result;
}

export function deepClone(value) {
    if (typeof structuredClone === "function") return structuredClone(value);
    return JSON.parse(JSON.stringify(value));
}

export function deepFreeze(value) {
    if (!value || typeof value !== "object" || Object.isFrozen(value)) return value;
    Object.getOwnPropertyNames(value).forEach((key) => deepFreeze(value[key]));
    return Object.freeze(value);
}

export function get(object, path, fallback = undefined) {
    if (!path) return object ?? fallback;

    const value = String(path)
        .replace(/\[(\w+)\]/g, ".$1")
        .split(".")
        .filter(Boolean)
        .reduce((current, key) => current?.[key], object);

    return value === undefined ? fallback : value;
}

/* --------------------------------------------------------------------------
   String Helpers
   -------------------------------------------------------------------------- */

export function escapeHtml(value) {
    const element = document.createElement("span");
    element.textContent = String(value ?? "");
    return element.innerHTML;
}

export function normalizeText(value) {
    return String(value ?? "")
        .normalize("NFKC")
        .trim()
        .replace(/\s+/g, " ");
}

export function slugify(value) {
    return normalizeText(value)
        .toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, "-")
        .replace(/^-+|-+$/g, "");
}

export function truncate(value, maxLength = 100, suffix = "…") {
    const text = String(value ?? "");
    const limit = Math.max(0, toInteger(maxLength, 100));
    if (text.length <= limit) return text;
    return `${text.slice(0, Math.max(0, limit - suffix.length)).trimEnd()}${suffix}`;
}

export function capitalize(value) {
    const text = String(value ?? "");
    return text ? `${text.charAt(0).toUpperCase()}${text.slice(1)}` : "";
}

export function getInitials(value, maxLetters = 2) {
    const words = normalizeText(value).split(" ").filter(Boolean);
    if (words.length === 0) return "?";

    return words
        .slice(0, Math.max(1, toInteger(maxLetters, 2)))
        .map((word) => word.charAt(0).toUpperCase())
        .join("");
}

export function maskValue(value, visibleStart = 2, visibleEnd = 2, mask = "•") {
    const text = String(value ?? "");
    const start = Math.max(0, toInteger(visibleStart, 2));
    const end = Math.max(0, toInteger(visibleEnd, 2));

    if (text.length <= start + end) return mask.repeat(text.length);
    return `${text.slice(0, start)}${mask.repeat(text.length - start - end)}${text.slice(-end)}`;
}

/* --------------------------------------------------------------------------
   Date, Time, and Number Formatting
   -------------------------------------------------------------------------- */

export function parseDate(value) {
    if (value instanceof Date) return new Date(value.getTime());
    if (value === null || value === undefined || value === "") return null;

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDate(value, options = {}) {
    const date = parseDate(value);
    if (!date) return options.fallback ?? "-";

    return new Intl.DateTimeFormat(options.locale || DEFAULT_LOCALE, {
        timeZone: options.timeZone || DEFAULT_TIME_ZONE,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        ...options.format
    }).format(date);
}

export function formatDateTime(value, options = {}) {
    const date = parseDate(value);
    if (!date) return options.fallback ?? "-";

    return new Intl.DateTimeFormat(options.locale || DEFAULT_LOCALE, {
        timeZone: options.timeZone || DEFAULT_TIME_ZONE,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: options.showSeconds ? "2-digit" : undefined,
        hour12: false,
        ...options.format
    }).format(date);
}

export function formatRelativeTime(value, options = {}) {
    const date = parseDate(value);
    if (!date) return options.fallback ?? "-";

    const difference = date.getTime() - Date.now();
    const units = [
        [31536000000, "year"],
        [2592000000, "month"],
        [604800000, "week"],
        [86400000, "day"],
        [3600000, "hour"],
        [60000, "minute"],
        [1000, "second"]
    ];

    const [divisor, unit] = units.find(([size]) => Math.abs(difference) >= size) || units.at(-1);
    const amount = Math.round(difference / divisor);

    return new Intl.RelativeTimeFormat(options.locale || DEFAULT_LOCALE, {
        numeric: options.numeric || "auto"
    }).format(amount, unit);
}

export function formatNumber(value, options = {}) {
    return new Intl.NumberFormat(options.locale || DEFAULT_LOCALE, {
        maximumFractionDigits: options.maximumFractionDigits ?? 2,
        minimumFractionDigits: options.minimumFractionDigits ?? 0,
        ...options.format
    }).format(toNumber(value));
}

export function formatFileSize(bytes, decimals = 2) {
    const size = Math.max(0, toNumber(bytes));
    if (size === 0) return "0 B";

    const units = ["B", "KB", "MB", "GB", "TB"];
    const index = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1);
    const value = size / (1024 ** index);
    return `${value.toFixed(Math.max(0, decimals))} ${units[index]}`;
}

/* --------------------------------------------------------------------------
   Validation Helpers
   -------------------------------------------------------------------------- */

export function isValidEmail(value) {
    const email = normalizeText(value);
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

export function isValidIPv4(value) {
    const parts = String(value ?? "").trim().split(".");
    return parts.length === 4 && parts.every((part) => {
        if (!/^\d{1,3}$/.test(part)) return false;
        if (part.length > 1 && part.startsWith("0")) return false;
        const number = Number(part);
        return number >= 0 && number <= 255;
    });
}

export function isValidUrl(value, options = {}) {
    try {
        const url = new URL(String(value), options.baseUrl || window.location.origin);
        return (options.protocols || ["http:", "https:"]).includes(url.protocol);
    } catch {
        return false;
    }
}

export function validateRequired(value) {
    return !isEmpty(value);
}

export function validateLength(value, options = {}) {
    const length = String(value ?? "").length;
    const min = options.min ?? 0;
    const max = options.max ?? Infinity;
    return length >= min && length <= max;
}

/* --------------------------------------------------------------------------
   Function Control
   -------------------------------------------------------------------------- */

export function debounce(callback, delay = 300) {
    assertFunction(callback, "debounce callback");
    let timerId = null;

    function debounced(...args) {
        window.clearTimeout(timerId);
        timerId = window.setTimeout(() => callback.apply(this, args), delay);
    }

    debounced.cancel = () => {
        window.clearTimeout(timerId);
        timerId = null;
    };

    debounced.flush = (...args) => {
        debounced.cancel();
        return callback(...args);
    };

    return debounced;
}

export function throttle(callback, delay = 300) {
    assertFunction(callback, "throttle callback");
    let lastRun = 0;
    let timerId = null;

    return function throttled(...args) {
        const remaining = delay - (Date.now() - lastRun);

        if (remaining <= 0) {
            window.clearTimeout(timerId);
            lastRun = Date.now();
            callback.apply(this, args);
            return;
        }

        window.clearTimeout(timerId);
        timerId = window.setTimeout(() => {
            lastRun = Date.now();
            callback.apply(this, args);
        }, remaining);
    };
}

export function once(callback) {
    assertFunction(callback, "once callback");
    let called = false;
    let result;

    return function runOnce(...args) {
        if (!called) {
            called = true;
            result = callback.apply(this, args);
        }
        return result;
    };
}

export function sleep(milliseconds = 0, signal = null) {
    return new Promise((resolve, reject) => {
        if (signal?.aborted) {
            reject(signal.reason || new DOMException("Aborted", "AbortError"));
            return;
        }

        const timerId = window.setTimeout(resolve, Math.max(0, milliseconds));
        signal?.addEventListener("abort", () => {
            window.clearTimeout(timerId);
            reject(signal.reason || new DOMException("Aborted", "AbortError"));
        }, { once: true });
    });
}

export async function retry(task, options = {}) {
    assertFunction(task, "retry task");
    const attempts = Math.max(1, toInteger(options.attempts, 3));
    const delay = Math.max(0, toInteger(options.delay, 500));
    let lastError;

    for (let attempt = 1; attempt <= attempts; attempt += 1) {
        try {
            return await task(attempt);
        } catch (error) {
            lastError = error;
            if (attempt === attempts || options.shouldRetry?.(error, attempt) === false) break;
            await sleep(delay * attempt, options.signal);
        }
    }

    throw lastError;
}

/* --------------------------------------------------------------------------
   URL and Query Helpers
   -------------------------------------------------------------------------- */

export function getQueryParams(search = window.location.search) {
    const params = new URLSearchParams(search);
    const result = {};

    for (const [key, value] of params.entries()) {
        if (Object.prototype.hasOwnProperty.call(result, key)) {
            result[key] = Array.isArray(result[key])
                ? [...result[key], value]
                : [result[key], value];
        } else {
            result[key] = value;
        }
    }

    return result;
}

export function buildQueryString(params = {}) {
    const query = new URLSearchParams();

    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === "") return;
        const values = Array.isArray(value) ? value : [value];
        values.forEach((item) => query.append(key, String(item)));
    });

    const result = query.toString();
    return result ? `?${result}` : "";
}

export function updateQueryParams(params = {}, options = {}) {
    const url = new URL(window.location.href);

    Object.entries(params).forEach(([key, value]) => {
        if (value === null || value === undefined || value === "") {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, String(value));
        }
    });

    const method = options.replace ? "replaceState" : "pushState";
    window.history[method](options.state || {}, "", `${url.pathname}${url.search}${url.hash}`);
    return url;
}

export function sanitizeReturnUrl(value, fallback = "/connectpro/") {
    if (!value) return fallback;

    try {
        const url = new URL(String(value), window.location.origin);
        if (url.origin !== window.location.origin) return fallback;
        return `${url.pathname}${url.search}${url.hash}`;
    } catch {
        return fallback;
    }
}

/* --------------------------------------------------------------------------
   Storage Helpers
   Do not store passwords, PHP session IDs, or authentication tokens here.
   -------------------------------------------------------------------------- */

export function createStorage(namespace, storage = window.localStorage) {
    const prefix = `${String(namespace || "connectpro").trim()}:`;

    return Object.freeze({
        get(key, fallback = null) {
            try {
                const raw = storage.getItem(`${prefix}${key}`);
                return raw === null ? fallback : JSON.parse(raw);
            } catch {
                return fallback;
            }
        },
        set(key, value) {
            storage.setItem(`${prefix}${key}`, JSON.stringify(value));
            return value;
        },
        remove(key) {
            storage.removeItem(`${prefix}${key}`);
        },
        clear() {
            Object.keys(storage)
                .filter((key) => key.startsWith(prefix))
                .forEach((key) => storage.removeItem(key));
        }
    });
}

/* --------------------------------------------------------------------------
   DOM and Form Helpers
   -------------------------------------------------------------------------- */

export function query(selector, root = document) {
    return root.querySelector(selector);
}

export function queryAll(selector, root = document) {
    return [...root.querySelectorAll(selector)];
}

export function createElement(tagName, options = {}) {
    const element = document.createElement(tagName);

    if (options.className) element.className = options.className;
    if (options.text !== undefined) element.textContent = String(options.text);
    if (options.html !== undefined) element.innerHTML = String(options.html);

    Object.entries(options.attributes || {}).forEach(([name, value]) => {
        if (value !== false && value !== null && value !== undefined) {
            element.setAttribute(name, value === true ? "" : String(value));
        }
    });

    Object.entries(options.dataset || {}).forEach(([name, value]) => {
        element.dataset[name] = String(value);
    });

    (options.children || []).forEach((child) => {
        element.append(child instanceof Node ? child : document.createTextNode(String(child)));
    });

    return element;
}

export function serializeForm(form, options = {}) {
    if (!(form instanceof HTMLFormElement)) {
        throw new TypeError("serializeForm() requires an HTMLFormElement.");
    }

    const data = {};

    for (const [key, value] of new FormData(form).entries()) {
        const normalized = value instanceof File && options.files !== true
            ? value.name
            : value;

        if (Object.prototype.hasOwnProperty.call(data, key)) {
            data[key] = Array.isArray(data[key])
                ? [...data[key], normalized]
                : [data[key], normalized];
        } else {
            data[key] = normalized;
        }
    }

    return data;
}

export function setFormValues(form, values = {}) {
    if (!(form instanceof HTMLFormElement)) {
        throw new TypeError("setFormValues() requires an HTMLFormElement.");
    }

    Object.entries(values).forEach(([name, value]) => {
        const fields = [...form.elements].filter((field) => field.name === name);

        fields.forEach((field) => {
            if (field instanceof HTMLInputElement && ["checkbox", "radio"].includes(field.type)) {
                const values = Array.isArray(value) ? value.map(String) : [String(value)];
                field.checked = values.includes(field.value);
            } else if ("value" in field) {
                field.value = value ?? "";
            }
        });
    });
}

export function clearFormErrors(form) {
    form.querySelectorAll("[aria-invalid=\"true\"]").forEach((field) => {
        field.removeAttribute("aria-invalid");
    });

    form.querySelectorAll("[data-field-error]").forEach((element) => {
        element.textContent = "";
        element.hidden = true;
    });
}

export function applyFormErrors(form, errors = {}) {
    clearFormErrors(form);

    Object.entries(errors).forEach(([name, messages]) => {
        const field = form.elements.namedItem(name);
        const error = form.querySelector(`[data-field-error="${cssEscape(name)}"]`);

        if (field instanceof HTMLElement) field.setAttribute("aria-invalid", "true");
        if (error) {
            error.textContent = Array.isArray(messages) ? messages[0] : String(messages);
            error.hidden = false;
        }
    });
}

export function generateId(prefix = "cp") {
    if (globalThis.crypto?.randomUUID) return `${prefix}-${crypto.randomUUID()}`;
    return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export function downloadBlob(blob, fileName = "download") {
    if (!(blob instanceof Blob)) throw new TypeError("downloadBlob() requires a Blob.");

    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = fileName;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function cssEscape(value) {
    return globalThis.CSS?.escape
        ? CSS.escape(String(value))
        : String(value).replace(/["\\]/g, "\\$&");
}

function assertFunction(value, label) {
    if (typeof value !== "function") throw new TypeError(`${label} must be a function.`);
}

const utils = Object.freeze({
    isPlainObject,
    isEmpty,
    toNumber,
    toInteger,
    toBoolean,
    clamp,
    unique,
    compact,
    chunk,
    deepClone,
    deepFreeze,
    get,
    escapeHtml,
    normalizeText,
    slugify,
    truncate,
    capitalize,
    getInitials,
    maskValue,
    parseDate,
    formatDate,
    formatDateTime,
    formatRelativeTime,
    formatNumber,
    formatFileSize,
    isValidEmail,
    isValidIPv4,
    isValidUrl,
    validateRequired,
    validateLength,
    debounce,
    throttle,
    once,
    sleep,
    retry,
    getQueryParams,
    buildQueryString,
    updateQueryParams,
    sanitizeReturnUrl,
    createStorage,
    query,
    queryAll,
    createElement,
    serializeForm,
    setFormValues,
    clearFormErrors,
    applyFormErrors,
    generateId,
    downloadBlob
});

export default utils;
