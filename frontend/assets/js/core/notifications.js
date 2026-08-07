/**
 * ConnectPro Notification Manager
 * File: frontend/assets/js/core/notifications.js
 * Dependencies: api.js, auth.js, components.js
 */

"use strict";

import api from "./api.js";
import auth, { AUTH_EVENTS } from "./auth.js";
import components from "./components.js";

const DEFAULT_CONFIG = Object.freeze({
    endpoints: Object.freeze({
        list: "notifications/list.php",
        unreadCount: "notifications/unread-count.php",
        markRead: "notifications/mark-read.php",
        markAllRead: "notifications/mark-all-read.php",
        remove: "notifications/delete.php"
    }),
    pollInterval: 60000,
    pageSize: 20,
    badgeSelector: "[data-notification-count]",
    listSelector: "[data-notification-list]",
    emptySelector: "[data-notification-empty]",
    loadingSelector: "[data-notification-loading]",
    browserNotifications: false,
    showToastForNew: true,
    maxSeenIds: 200
});

export const NOTIFICATION_EVENTS = Object.freeze({
    UPDATED: "connectpro:notifications-updated",
    RECEIVED: "connectpro:notification-received",
    READ: "connectpro:notification-read",
    DELETED: "connectpro:notification-deleted"
});

let overrides = {};
let initialized = false;
let pollTimerId = null;
let unreadCount = 0;
let notifications = [];
let seenIds = new Set();
let visibilityHandler = null;
let authHandler = null;

export function configureNotifications(options = {}) {
    assertPlainObject(options, "Notification configuration");

    overrides = {
        ...overrides,
        ...options,
        endpoints: {
            ...(overrides.endpoints || {}),
            ...(options.endpoints || {})
        }
    };

    return getConfig();
}

export async function initNotifications(options = {}) {
    if (initialized) {
        return refreshNotifications();
    }

    configureNotifications(options);
    initialized = true;
    bindNotificationActions(options.root || document);

    visibilityHandler = () => {
        if (document.visibilityState === "visible" && auth.isAuthenticated()) {
            refreshNotifications({ silent: true }).catch(() => {});
        }
    };

    authHandler = (event) => {
        if (event.detail?.user) {
            refreshNotifications({ silent: true }).catch(() => {});
            startNotificationPolling();
        } else {
            resetNotificationState();
            stopNotificationPolling();
        }
    };

    document.addEventListener("visibilitychange", visibilityHandler);
    window.addEventListener(AUTH_EVENTS.CHANGED, authHandler);

    if (!auth.isAuthenticated()) {
        renderNotifications();
        return [];
    }

    const result = await refreshNotifications({ silent: true });
    startNotificationPolling();
    return result;
}

export function destroyNotifications() {
    stopNotificationPolling();

    if (visibilityHandler) {
        document.removeEventListener("visibilitychange", visibilityHandler);
    }

    if (authHandler) {
        window.removeEventListener(AUTH_EVENTS.CHANGED, authHandler);
    }

    visibilityHandler = null;
    authHandler = null;
    initialized = false;
}

export async function fetchNotifications(options = {}) {
    const config = getConfig();
    const response = await api.get(config.endpoints.list, {
        query: {
            page: options.page || 1,
            limit: options.limit || config.pageSize,
            unread_only: options.unreadOnly ? 1 : undefined
        },
        requestKey: "notifications-list",
        cancelPrevious: true
    });

    const items = Array.isArray(response)
        ? response
        : response?.notifications || response?.items || [];

    return items.map(normalizeNotification).filter(Boolean);
}

export async function fetchUnreadCount() {
    const response = await api.get(getConfig().endpoints.unreadCount, {
        requestKey: "notifications-unread-count",
        cancelPrevious: true
    });

    const count = Number(response?.unreadCount ?? response?.unread_count ?? response?.count ?? 0);
    unreadCount = Number.isFinite(count) ? Math.max(0, count) : 0;
    renderUnreadCount();
    return unreadCount;
}

export async function refreshNotifications(options = {}) {
    if (!auth.isAuthenticated()) {
        resetNotificationState();
        return [];
    }

    setNotificationLoading(true);

    try {
        const [items] = await Promise.all([
            fetchNotifications(options),
            fetchUnreadCount()
        ]);

        const newItems = findNewNotifications(items);
        notifications = items;
        rememberNotificationIds(items);
        renderNotifications(options.root || document);

        if (!options.silent) {
            announceNewNotifications(newItems);
        }

        dispatchEvent(NOTIFICATION_EVENTS.UPDATED, {
            notifications: getNotifications(),
            unreadCount
        });

        return getNotifications();
    } catch (error) {
        if (!options.silent) {
            components.toast.error(error.message || "โหลดการแจ้งเตือนไม่สำเร็จ");
        }
        throw error;
    } finally {
        setNotificationLoading(false);
    }
}

export async function markNotificationRead(notificationId, options = {}) {
    const id = normalizeId(notificationId);

    await api.patch(getConfig().endpoints.markRead, { id }, {
        requestKey: `notification-read-${id}`
    });

    notifications = notifications.map((item) =>
        item.id === id ? { ...item, isRead: true } : item
    );
    unreadCount = Math.max(0, unreadCount - 1);
    renderNotifications(options.root || document);
    renderUnreadCount();
    dispatchEvent(NOTIFICATION_EVENTS.READ, { id });
    return true;
}

export async function markAllNotificationsRead(options = {}) {
    await api.patch(getConfig().endpoints.markAllRead, {}, {
        requestKey: "notifications-read-all"
    });

    notifications = notifications.map((item) => ({ ...item, isRead: true }));
    unreadCount = 0;
    renderNotifications(options.root || document);
    renderUnreadCount();
    dispatchEvent(NOTIFICATION_EVENTS.READ, { all: true });
    return true;
}

export async function deleteNotification(notificationId, options = {}) {
    const id = normalizeId(notificationId);
    const target = notifications.find((item) => item.id === id);

    await api.delete(getConfig().endpoints.remove, {
        query: { id },
        requestKey: `notification-delete-${id}`
    });

    notifications = notifications.filter((item) => item.id !== id);

    if (target && !target.isRead) {
        unreadCount = Math.max(0, unreadCount - 1);
    }

    renderNotifications(options.root || document);
    renderUnreadCount();
    dispatchEvent(NOTIFICATION_EVENTS.DELETED, { id });
    return true;
}

export function getNotifications() {
    return notifications.map((item) => ({ ...item }));
}

export function getUnreadCount() {
    return unreadCount;
}

export function startNotificationPolling(interval = getConfig().pollInterval) {
    stopNotificationPolling();

    const milliseconds = Number(interval);
    if (!Number.isFinite(milliseconds) || milliseconds < 30000) {
        throw new RangeError("Notification polling interval must be at least 30 seconds.");
    }

    pollTimerId = window.setInterval(() => {
        if (document.visibilityState === "visible" && auth.isAuthenticated()) {
            refreshNotifications({ silent: false }).catch(() => {});
        }
    }, milliseconds);
}

export function stopNotificationPolling() {
    if (pollTimerId !== null) {
        window.clearInterval(pollTimerId);
        pollTimerId = null;
    }
}

export async function requestBrowserNotificationPermission() {
    if (!("Notification" in window)) {
        return "unsupported";
    }

    if (Notification.permission === "granted") {
        return "granted";
    }

    return Notification.requestPermission();
}

export function showBrowserNotification(notification) {
    const config = getConfig();

    if (
        !config.browserNotifications ||
        !("Notification" in window) ||
        Notification.permission !== "granted"
    ) {
        return null;
    }

    const item = normalizeNotification(notification);
    if (!item) return null;

    const browserNotification = new Notification(item.title, {
        body: item.message,
        tag: `connectpro-notification-${item.id}`,
        data: { url: item.url }
    });

    browserNotification.onclick = () => {
        window.focus();
        if (item.url) safeNavigate(item.url);
        browserNotification.close();
    };

    return browserNotification;
}

export function renderNotifications(root = document) {
    const config = getConfig();
    const containers = root.querySelectorAll(config.listSelector);

    containers.forEach((container) => {
        container.replaceChildren(...notifications.map(createNotificationElement));
    });

    root.querySelectorAll(config.emptySelector).forEach((element) => {
        element.hidden = notifications.length > 0;
    });

    renderUnreadCount(root);
}

export function bindNotificationActions(root = document) {
    if (root.dataset?.notificationActionsReady === "true") return;
    if (root.dataset) root.dataset.notificationActionsReady = "true";

    root.addEventListener("click", async (event) => {
        const readButton = event.target.closest("[data-notification-read]");
        const deleteButton = event.target.closest("[data-notification-delete]");
        const readAllButton = event.target.closest("[data-notification-read-all]");
        const link = event.target.closest("[data-notification-link]");

        try {
            if (readButton) {
                await markNotificationRead(readButton.dataset.notificationRead);
            } else if (deleteButton) {
                await deleteNotification(deleteButton.dataset.notificationDelete);
            } else if (readAllButton) {
                await markAllNotificationsRead();
                components.toast.success("อ่านการแจ้งเตือนทั้งหมดแล้ว");
            } else if (link) {
                const id = normalizeId(link.dataset.notificationId);
                const item = notifications.find((entry) => entry.id === id);
                if (item && !item.isRead) await markNotificationRead(id);
                if (item?.url) safeNavigate(item.url);
            }
        } catch (error) {
            components.toast.error(error.message || "ดำเนินการกับการแจ้งเตือนไม่สำเร็จ");
        }
    });
}

function createNotificationElement(item) {
    const element = document.createElement("article");
    element.className = `cp-notification-item${item.isRead ? "" : " is-unread"}`;
    element.dataset.notificationId = String(item.id);

    const content = document.createElement("button");
    content.type = "button";
    content.className = "cp-notification-item__content";
    content.dataset.notificationLink = "";
    content.dataset.notificationId = String(item.id);

    const title = document.createElement("strong");
    title.className = "cp-notification-item__title";
    title.textContent = item.title;

    const message = document.createElement("span");
    message.className = "cp-notification-item__message";
    message.textContent = item.message;

    const time = document.createElement("time");
    time.className = "cp-notification-item__time";
    time.dateTime = item.createdAt || "";
    time.textContent = formatRelativeTime(item.createdAt);

    content.append(title, message, time);

    const actions = document.createElement("div");
    actions.className = "cp-notification-item__actions";

    if (!item.isRead) {
        const readButton = document.createElement("button");
        readButton.type = "button";
        readButton.className = "cp-icon-button";
        readButton.dataset.notificationRead = String(item.id);
        readButton.setAttribute("aria-label", "ทำเครื่องหมายว่าอ่านแล้ว");
        readButton.textContent = "✓";
        actions.appendChild(readButton);
    }

    const deleteButton = document.createElement("button");
    deleteButton.type = "button";
    deleteButton.className = "cp-icon-button";
    deleteButton.dataset.notificationDelete = String(item.id);
    deleteButton.setAttribute("aria-label", "ลบการแจ้งเตือน");
    deleteButton.textContent = "×";
    actions.appendChild(deleteButton);

    element.append(content, actions);
    return element;
}

function renderUnreadCount(root = document) {
    const config = getConfig();

    root.querySelectorAll(config.badgeSelector).forEach((badge) => {
        badge.textContent = unreadCount > 99 ? "99+" : String(unreadCount);
        badge.hidden = unreadCount === 0;
        badge.setAttribute("aria-label", `มีการแจ้งเตือนที่ยังไม่อ่าน ${unreadCount} รายการ`);
    });
}

function setNotificationLoading(loading) {
    document.querySelectorAll(getConfig().loadingSelector).forEach((element) => {
        element.hidden = !loading;
    });
}

function announceNewNotifications(items) {
    if (items.length === 0) return;
    const config = getConfig();

    items.forEach((item) => {
        if (config.showToastForNew) {
            components.toast.info(item.message, {
                title: item.title,
                duration: 5000
            });
        }
        showBrowserNotification(item);
        dispatchEvent(NOTIFICATION_EVENTS.RECEIVED, { notification: item });
    });
}

function findNewNotifications(items) {
    if (seenIds.size === 0) return [];
    return items.filter((item) => !item.isRead && !seenIds.has(item.id));
}

function rememberNotificationIds(items) {
    items.forEach((item) => seenIds.add(item.id));
    const max = getConfig().maxSeenIds;

    if (seenIds.size > max) {
        seenIds = new Set([...seenIds].slice(-max));
    }
}

function normalizeNotification(value) {
    if (!value || typeof value !== "object") return null;

    const id = Number(value.id || value.notification_id || 0);
    if (!Number.isFinite(id) || id < 1) return null;

    return Object.freeze({
        id,
        type: String(value.type || "info").toLowerCase(),
        title: String(value.title || "การแจ้งเตือน"),
        message: String(value.message || ""),
        url: normalizeInternalUrl(value.url || value.action_url || ""),
        isRead: Boolean(value.is_read ?? value.isRead ?? false),
        createdAt: value.created_at || value.createdAt || null
    });
}

function normalizeInternalUrl(value) {
    if (!value) return "";

    try {
        const url = new URL(String(value), window.location.origin);
        return url.origin === window.location.origin
            ? `${url.pathname}${url.search}${url.hash}`
            : "";
    } catch {
        return "";
    }
}

function formatRelativeTime(value) {
    if (!value) return "";

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "";

    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const ranges = [
        [60, "second"],
        [60, "minute"],
        [24, "hour"],
        [7, "day"],
        [4.345, "week"],
        [12, "month"],
        [Infinity, "year"]
    ];

    let valueInUnit = seconds;
    let unit = "second";

    for (const [divisor, nextUnit] of ranges) {
        unit = nextUnit;
        if (Math.abs(valueInUnit) < divisor) break;
        valueInUnit /= divisor;
    }

    return new Intl.RelativeTimeFormat("th", { numeric: "auto" })
        .format(Math.round(valueInUnit), unit);
}

function resetNotificationState() {
    notifications = [];
    unreadCount = 0;
    seenIds.clear();
    renderNotifications();
}

function normalizeId(value) {
    const id = Number(value);
    if (!Number.isInteger(id) || id < 1) {
        throw new TypeError("Notification ID is invalid.");
    }
    return id;
}

function safeNavigate(path) {
    const url = new URL(path, window.location.origin);
    if (url.origin !== window.location.origin) return;
    window.location.assign(`${url.pathname}${url.search}${url.hash}`);
}

function getConfig() {
    return {
        ...DEFAULT_CONFIG,
        ...overrides,
        endpoints: {
            ...DEFAULT_CONFIG.endpoints,
            ...(overrides.endpoints || {})
        }
    };
}

function dispatchEvent(name, detail) {
    window.dispatchEvent(new CustomEvent(name, { detail }));
}

function assertPlainObject(value, label) {
    if (
        value === null ||
        typeof value !== "object" ||
        Object.getPrototypeOf(value) !== Object.prototype
    ) {
        throw new TypeError(`${label} must be an object.`);
    }
}

const notificationManager = Object.freeze({
    configure: configureNotifications,
    init: initNotifications,
    destroy: destroyNotifications,
    fetch: fetchNotifications,
    refresh: refreshNotifications,
    fetchUnreadCount,
    getAll: getNotifications,
    getUnreadCount,
    markRead: markNotificationRead,
    markAllRead: markAllNotificationsRead,
    delete: deleteNotification,
    render: renderNotifications,
    bindActions: bindNotificationActions,
    startPolling: startNotificationPolling,
    stopPolling: stopNotificationPolling,
    requestBrowserPermission: requestBrowserNotificationPermission,
    showBrowserNotification,
    events: NOTIFICATION_EVENTS
});

export default notificationManager;
