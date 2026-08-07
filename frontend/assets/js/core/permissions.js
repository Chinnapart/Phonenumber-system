/**
 * ConnectPro Permission Manager
 * File: frontend/assets/js/core/permissions.js
 * Dependency: ./auth.js
 *
 * Responsibilities:
 * - Define permission names in one place
 * - Check single, multiple, any, or all permissions
 * - Apply permission-based visibility and disabled states to UI elements
 * - Support admin bypass when configured
 * - Provide scoped permission helpers for page modules
 *
 * Security note:
 * Permission checks in this file improve the user experience only.
 * Every PHP endpoint must validate the authenticated session, role,
 * permission, resource ownership, CSRF token, and request data again.
 */

"use strict";

import auth, { AUTH_EVENTS } from "./auth.js";

/**
 * Canonical permission names used by ConnectPro.
 * Keep values synchronized with the permissions stored in the database.
 */
export const PERMISSIONS = deepFreeze({
    DASHBOARD: {
        VIEW: "dashboard.view",
        VIEW_STATISTICS: "dashboard.statistics.view"
    },
    CONTACTS: {
        VIEW: "contacts.view",
        CREATE: "contacts.create",
        UPDATE: "contacts.update",
        DELETE: "contacts.delete",
        RESTORE: "contacts.restore",
        EXPORT: "contacts.export",
        IMPORT: "contacts.import"
    },
    DEPARTMENTS: {
        VIEW: "departments.view",
        CREATE: "departments.create",
        UPDATE: "departments.update",
        DELETE: "departments.delete",
        MANAGE: "departments.manage"
    },
    LOCATIONS: {
        VIEW: "locations.view",
        CREATE: "locations.create",
        UPDATE: "locations.update",
        DELETE: "locations.delete",
        MANAGE: "locations.manage"
    },
    USERS: {
        VIEW: "users.view",
        CREATE: "users.create",
        UPDATE: "users.update",
        DELETE: "users.delete",
        ACTIVATE: "users.activate",
        DEACTIVATE: "users.deactivate",
        RESET_PASSWORD: "users.password.reset",
        ASSIGN_ROLE: "users.role.assign"
    },
    ROLES: {
        VIEW: "roles.view",
        CREATE: "roles.create",
        UPDATE: "roles.update",
        DELETE: "roles.delete",
        ASSIGN_PERMISSIONS: "roles.permissions.assign"
    },
    ACTIVITY_LOG: {
        VIEW: "activity-log.view",
        EXPORT: "activity-log.export",
        DELETE: "activity-log.delete"
    },
    SETTINGS: {
        VIEW: "settings.view",
        UPDATE_GENERAL: "settings.general.update",
        UPDATE_SECURITY: "settings.security.update",
        UPDATE_APPEARANCE: "settings.appearance.update",
        BACKUP: "settings.backup",
        RESTORE: "settings.restore"
    }
});

/**
 * Default permission sets for the two ConnectPro scopes.
 * The backend/database remains the source of truth.
 */
export const ROLE_PERMISSION_DEFAULTS = deepFreeze({
    admin: ["*"],
    user: [
        PERMISSIONS.DASHBOARD.VIEW,
        PERMISSIONS.CONTACTS.VIEW,
        PERMISSIONS.DEPARTMENTS.VIEW,
        PERMISSIONS.LOCATIONS.VIEW
    ]
});

const DEFAULT_CONFIG = Object.freeze({
    adminRole: "admin",
    adminBypass: true,
    wildcard: "*",
    attribute: "data-permission",
    modeAttribute: "data-permission-mode",
    deniedAttribute: "data-permission-denied",
    deniedClass: "is-permission-denied",
    defaultMode: "all",
    hideUnauthorized: true,
    observeDom: false
});

const PERMISSION_EVENTS = Object.freeze({
    UPDATED: "connectpro:permissions-updated",
    DENIED: "connectpro:permission-denied"
});

let configOverrides = {};
let permissionSet = new Set();
let initialized = false;
let observer = null;
let authChangedHandler = null;

export function configurePermissions(overrides = {}) {
    if (!isPlainObject(overrides)) {
        throw new TypeError("Permission configuration must be an object.");
    }

    configOverrides = {
        ...configOverrides,
        ...overrides
    };

    return getConfig();
}

/**
 * Initialize permission state from the current authenticated user.
 */
export function initPermissions(options = {}) {
    if (initialized) {
        refreshPermissions(options.root || document);
        return;
    }

    initialized = true;
    configurePermissions(options);
    syncFromCurrentUser();

    authChangedHandler = (event) => {
        syncFromUser(event.detail?.user || null);
        applyPermissions(options.root || document);
    };

    window.addEventListener(AUTH_EVENTS.CHANGED, authChangedHandler);

    if (getConfig().observeDom) {
        startPermissionObserver(options.root || document.body);
    }

    applyPermissions(options.root || document);
}

/**
 * Stop observers and remove event listeners.
 */
export function destroyPermissions() {
    if (authChangedHandler) {
        window.removeEventListener(AUTH_EVENTS.CHANGED, authChangedHandler);
        authChangedHandler = null;
    }

    stopPermissionObserver();
    permissionSet.clear();
    initialized = false;
}

/**
 * Replace the in-memory permission list.
 */
export function setPermissions(permissions = []) {
    permissionSet = new Set(normalizePermissions(permissions));
    dispatchPermissionEvent(PERMISSION_EVENTS.UPDATED, {
        permissions: getPermissions()
    });

    return getPermissions();
}

/**
 * Synchronize permissions from a normalized auth.js user object.
 */
export function syncFromUser(user) {
    if (!user) {
        return setPermissions([]);
    }

    const config = getConfig();
    const role = normalizeValue(user.role);
    const userPermissions = Array.isArray(user.permissions)
        ? user.permissions
        : [];

    if (config.adminBypass && role === normalizeValue(config.adminRole)) {
        return setPermissions([config.wildcard, ...userPermissions]);
    }

    if (userPermissions.length > 0) {
        return setPermissions(userPermissions);
    }

    return setPermissions(ROLE_PERMISSION_DEFAULTS[role] || []);
}

export function syncFromCurrentUser() {
    return syncFromUser(auth.getCurrentUser());
}

export function getPermissions() {
    return [...permissionSet];
}

export function has(permission) {
    const normalized = normalizePermission(permission);

    if (!normalized) {
        return false;
    }

    const config = getConfig();

    return (
        permissionSet.has(config.wildcard) ||
        permissionSet.has(normalized) ||
        hasNamespaceWildcard(normalized, config.wildcard)
    );
}

export function hasAll(...permissions) {
    const required = normalizePermissions(flattenValues(permissions));
    return required.length > 0 && required.every(has);
}

export function hasAny(...permissions) {
    const required = normalizePermissions(flattenValues(permissions));
    return required.length > 0 && required.some(has);
}

export function lacks(permission) {
    return !has(permission);
}

/**
 * Assert one permission and throw PermissionError when denied.
 */
export function authorize(permission, options = {}) {
    if (has(permission)) {
        return true;
    }

    const error = new PermissionError(
        options.message || "บัญชีนี้ไม่มีสิทธิ์ดำเนินการ",
        {
            permission: normalizePermission(permission),
            action: options.action || null,
            resource: options.resource || null
        }
    );

    dispatchPermissionEvent(PERMISSION_EVENTS.DENIED, {
        error,
        permission: error.permission
    });

    if (options.throwError !== false) {
        throw error;
    }

    return false;
}

/**
 * Assert multiple permissions using all or any matching mode.
 */
export function authorizeMany(permissions, options = {}) {
    const required = normalizePermissions(permissions);
    const mode = normalizeMode(options.mode);
    const allowed = mode === "any" ? hasAny(required) : hasAll(required);

    if (allowed) {
        return true;
    }

    const error = new PermissionError(
        options.message || "บัญชีนี้ไม่มีสิทธิ์ครบตามที่กำหนด",
        {
            permission: required,
            action: options.action || null,
            resource: options.resource || null
        }
    );

    dispatchPermissionEvent(PERMISSION_EVENTS.DENIED, {
        error,
        permissions: required,
        mode
    });

    if (options.throwError !== false) {
        throw error;
    }

    return false;
}

/**
 * Evaluate a permission expression.
 * Examples:
 * can("contacts.view")
 * can(["contacts.update", "contacts.delete"], "any")
 */
export function can(permissionOrList, mode = "all") {
    const required = normalizePermissions(permissionOrList);

    if (required.length === 0) {
        return false;
    }

    return normalizeMode(mode) === "any"
        ? required.some(has)
        : required.every(has);
}

/**
 * Apply permission rules to elements under the supplied root.
 *
 * Supported attributes:
 * data-permission="contacts.create"
 * data-permission="contacts.update,contacts.delete"
 * data-permission-mode="all|any"
 * data-permission-denied="hide|disable|readonly"
 */
export function applyPermissions(root = document) {
    if (!root?.querySelectorAll) {
        throw new TypeError("Permission root must support querySelectorAll().");
    }

    const config = getConfig();
    const selector = `[${config.attribute}]`;

    root.querySelectorAll(selector).forEach((element) => {
        applyElementPermission(element, config);
    });
}

export function refreshPermissions(root = document) {
    syncFromCurrentUser();
    applyPermissions(root);
    return getPermissions();
}

/**
 * Create a helper restricted to one permission namespace.
 * Example: const contacts = createPermissionScope("contacts");
 * contacts.has("create") checks "contacts.create".
 */
export function createPermissionScope(namespace) {
    const prefix = normalizePermission(namespace).replace(/\.+$/, "");

    if (!prefix) {
        throw new TypeError("Permission namespace is required.");
    }

    const qualify = (permission) => {
        const value = normalizePermission(permission);
        return value.startsWith(`${prefix}.`) ? value : `${prefix}.${value}`;
    };

    return Object.freeze({
        has: (permission) => has(qualify(permission)),
        hasAll: (...permissions) => hasAll(
            flattenValues(permissions).map(qualify)
        ),
        hasAny: (...permissions) => hasAny(
            flattenValues(permissions).map(qualify)
        ),
        authorize: (permission, options = {}) =>
            authorize(qualify(permission), options),
        can: (permissions, mode = "all") =>
            can(normalizePermissions(permissions).map(qualify), mode)
    });
}

export function startPermissionObserver(root = document.body) {
    stopPermissionObserver();

    if (!root || !("MutationObserver" in window)) {
        return false;
    }

    observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (!(node instanceof Element)) {
                    continue;
                }

                const config = getConfig();

                if (node.hasAttribute(config.attribute)) {
                    applyElementPermission(node, config);
                }

                applyPermissions(node);
            }
        }
    });

    observer.observe(root, {
        childList: true,
        subtree: true
    });

    return true;
}

export function stopPermissionObserver() {
    observer?.disconnect();
    observer = null;
}

export class PermissionError extends Error {
    constructor(message, options = {}) {
        super(message || "Permission denied");
        this.name = "PermissionError";
        this.code = "PERMISSION_DENIED";
        this.status = 403;
        this.permission = options.permission || null;
        this.action = options.action || null;
        this.resource = options.resource || null;
    }
}

function applyElementPermission(element, config) {
    const permissions = splitAttribute(element.getAttribute(config.attribute));
    const mode = normalizeMode(
        element.getAttribute(config.modeAttribute) || config.defaultMode
    );
    const allowed = can(permissions, mode);
    const deniedBehavior = normalizeDeniedBehavior(
        element.getAttribute(config.deniedAttribute),
        config
    );

    element.classList.toggle(config.deniedClass, !allowed);
    element.dataset.permissionAllowed = String(allowed);

    if (allowed) {
        restoreElement(element);
        return;
    }

    restrictElement(element, deniedBehavior);
}

function restrictElement(element, behavior) {
    rememberOriginalState(element);
    element.setAttribute("aria-disabled", "true");

    if (behavior === "hide") {
        element.hidden = true;
        element.setAttribute("aria-hidden", "true");
        return;
    }

    if (behavior === "readonly") {
        if ("readOnly" in element) {
            element.readOnly = true;
        }

        element.setAttribute("readonly", "");
        return;
    }

    if ("disabled" in element) {
        element.disabled = true;
    }

    element.setAttribute("disabled", "");
    element.tabIndex = -1;
}

function restoreElement(element) {
    element.hidden = false;
    element.removeAttribute("aria-hidden");
    element.removeAttribute("aria-disabled");

    const originalDisabled = element.dataset.permissionOriginalDisabled;
    const originalReadonly = element.dataset.permissionOriginalReadonly;
    const originalTabIndex = element.dataset.permissionOriginalTabIndex;

    if ("disabled" in element) {
        element.disabled = originalDisabled === "true";
    }

    if (originalDisabled !== "true") {
        element.removeAttribute("disabled");
    }

    if ("readOnly" in element) {
        element.readOnly = originalReadonly === "true";
    }

    if (originalReadonly !== "true") {
        element.removeAttribute("readonly");
    }

    if (originalTabIndex === "none") {
        element.removeAttribute("tabindex");
    } else if (originalTabIndex !== undefined) {
        element.tabIndex = Number(originalTabIndex);
    }
}

function rememberOriginalState(element) {
    if (element.dataset.permissionStateStored === "true") {
        return;
    }

    element.dataset.permissionStateStored = "true";
    element.dataset.permissionOriginalDisabled = String(
        "disabled" in element ? element.disabled : element.hasAttribute("disabled")
    );
    element.dataset.permissionOriginalReadonly = String(
        "readOnly" in element
            ? element.readOnly
            : element.hasAttribute("readonly")
    );
    element.dataset.permissionOriginalTabIndex = element.hasAttribute("tabindex")
        ? String(element.tabIndex)
        : "none";
}

function hasNamespaceWildcard(permission, wildcard) {
    const parts = permission.split(".");

    while (parts.length > 1) {
        parts.pop();

        if (permissionSet.has(`${parts.join(".")}.${wildcard}`)) {
            return true;
        }
    }

    return false;
}

function getConfig() {
    return {
        ...DEFAULT_CONFIG,
        ...configOverrides
    };
}

function normalizePermissions(value) {
    return [...new Set(
        flattenValues(Array.isArray(value) ? value : [value])
            .flatMap((item) =>
                typeof item === "string" && item.includes(",")
                    ? item.split(",")
                    : item
            )
            .map(normalizePermission)
            .filter(Boolean)
    )];
}

function normalizePermission(value) {
    return normalizeValue(value).replace(/\s+/g, "");
}

function normalizeValue(value) {
    return String(value || "").trim().toLowerCase();
}

function normalizeMode(value) {
    return normalizeValue(value) === "any" ? "any" : "all";
}

function normalizeDeniedBehavior(value, config) {
    const normalized = normalizeValue(value);

    if (["hide", "disable", "readonly"].includes(normalized)) {
        return normalized;
    }

    return config.hideUnauthorized ? "hide" : "disable";
}

function splitAttribute(value) {
    return String(value || "")
        .split(",")
        .map((item) => item.trim())
        .filter(Boolean);
}

function flattenValues(values) {
    return values.flat(Infinity).filter(
        (value) => value !== undefined && value !== null
    );
}

function dispatchPermissionEvent(eventName, detail) {
    window.dispatchEvent(new CustomEvent(eventName, { detail }));
}

function isPlainObject(value) {
    return (
        value !== null &&
        typeof value === "object" &&
        Object.getPrototypeOf(value) === Object.prototype
    );
}

function deepFreeze(value) {
    if (!value || typeof value !== "object" || Object.isFrozen(value)) {
        return value;
    }

    Object.getOwnPropertyNames(value).forEach((property) => {
        deepFreeze(value[property]);
    });

    return Object.freeze(value);
}

const permissions = Object.freeze({
    configure: configurePermissions,
    init: initPermissions,
    destroy: destroyPermissions,
    set: setPermissions,
    syncFromUser,
    syncFromCurrentUser,
    getAll: getPermissions,
    has,
    hasAll,
    hasAny,
    lacks,
    can,
    authorize,
    authorizeMany,
    apply: applyPermissions,
    refresh: refreshPermissions,
    scope: createPermissionScope,
    startObserver: startPermissionObserver,
    stopObserver: stopPermissionObserver,
    definitions: PERMISSIONS,
    roleDefaults: ROLE_PERMISSION_DEFAULTS,
    events: PERMISSION_EVENTS
});

export { PERMISSION_EVENTS };
export default permissions;
