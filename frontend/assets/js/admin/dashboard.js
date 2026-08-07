/**
 * ConnectPro Admin Dashboard
 * File: frontend/assets/js/admin/dashboard.js
 *
 * Required core modules:
 * - ../core/api.js
 * - ../core/auth.js
 * - ../core/permissions.js
 * - ../core/components.js
 * - ../core/notifications.js
 * - ../core/utils.js
 */

"use strict";

import api, { ApiError } from "../core/api.js";
import auth from "../core/auth.js";
import permissions, { PERMISSIONS } from "../core/permissions.js";
import components from "../core/components.js";
import notifications from "../core/notifications.js";
import utils from "../core/utils.js";

const CONFIG = Object.freeze({
    endpoints: Object.freeze({
        summary: "admin/dashboard/summary.php",
        recentContacts: "admin/dashboard/recent-contacts.php",
        recentActivities: "admin/dashboard/recent-activities.php",
        departmentStats: "admin/dashboard/department-stats.php"
    }),
    refreshInterval: 5 * 60 * 1000,
    recentLimit: 8
});

const SELECTORS = Object.freeze({
    page: "[data-admin-dashboard]",
    refreshButton: "[data-dashboard-refresh]",
    lastUpdated: "[data-dashboard-last-updated]",
    totalContacts: "[data-stat=\"total-contacts\"]",
    activeContacts: "[data-stat=\"active-contacts\"]",
    totalDepartments: "[data-stat=\"total-departments\"]",
    totalUsers: "[data-stat=\"total-users\"]",
    recentContacts: "[data-recent-contacts]",
    recentContactsEmpty: "[data-recent-contacts-empty]",
    recentActivities: "[data-recent-activities]",
    recentActivitiesEmpty: "[data-recent-activities-empty]",
    departmentStats: "[data-department-stats]",
    departmentStatsEmpty: "[data-department-stats-empty]",
    logoutButton: "[data-logout]"
});

const state = {
    loading: false,
    refreshTimerId: null,
    summary: createEmptySummary(),
    recentContacts: [],
    recentActivities: [],
    departmentStats: []
};

let initialized = false;

async function initializeDashboard() {
    if (initialized) return;
    initialized = true;

    components.init();
    auth.init();

    try {
        const user = await auth.requireAuth({ roles: ["admin"] });
        if (!user) return;

        permissions.init();
        permissions.authorize(PERMISSIONS.DASHBOARD.VIEW);
        auth.hydrateUserElements(document, user);

        bindDashboardEvents();
        await notifications.init({ showToastForNew: true });
        await loadDashboard();
        startAutoRefresh();
    } catch (error) {
        handleDashboardError(error, "ไม่สามารถเริ่มต้นหน้า Dashboard ได้");
    }
}

export async function loadDashboard(options = {}) {
    if (state.loading) return;

    state.loading = true;
    setDashboardLoading(true);

    try {
        const results = await Promise.allSettled([
            loadSummary(),
            loadRecentContacts(),
            loadRecentActivities(),
            loadDepartmentStats()
        ]);

        const failures = results.filter((result) => result.status === "rejected");

        if (failures.length === results.length) {
            throw failures[0].reason;
        }

        renderDashboard();
        updateLastUpdated();

        if (failures.length > 0 && !options.silent) {
            components.toast.warning("ข้อมูลบางส่วนโหลดไม่สำเร็จ");
        } else if (options.showSuccess) {
            components.toast.success("อัปเดต Dashboard แล้ว", { duration: 2200 });
        }
    } catch (error) {
        if (!options.silent) {
            handleDashboardError(error, "โหลดข้อมูล Dashboard ไม่สำเร็จ");
        }
        throw error;
    } finally {
        state.loading = false;
        setDashboardLoading(false);
    }
}

async function loadSummary() {
    const response = await api.get(CONFIG.endpoints.summary, {
        requestKey: "admin-dashboard-summary",
        cancelPrevious: true
    });

    state.summary = normalizeSummary(response?.summary || response);
    return state.summary;
}

async function loadRecentContacts() {
    if (!permissions.has(PERMISSIONS.CONTACTS.VIEW)) {
        state.recentContacts = [];
        return [];
    }

    const response = await api.get(CONFIG.endpoints.recentContacts, {
        query: { limit: CONFIG.recentLimit },
        requestKey: "admin-dashboard-recent-contacts",
        cancelPrevious: true
    });

    const items = Array.isArray(response)
        ? response
        : response?.contacts || response?.items || [];

    state.recentContacts = items.map(normalizeContact).filter(Boolean);
    return state.recentContacts;
}

async function loadRecentActivities() {
    if (!permissions.has(PERMISSIONS.ACTIVITY_LOG.VIEW)) {
        state.recentActivities = [];
        return [];
    }

    const response = await api.get(CONFIG.endpoints.recentActivities, {
        query: { limit: CONFIG.recentLimit },
        requestKey: "admin-dashboard-recent-activities",
        cancelPrevious: true
    });

    const items = Array.isArray(response)
        ? response
        : response?.activities || response?.items || [];

    state.recentActivities = items.map(normalizeActivity).filter(Boolean);
    return state.recentActivities;
}

async function loadDepartmentStats() {
    if (!permissions.has(PERMISSIONS.DEPARTMENTS.VIEW)) {
        state.departmentStats = [];
        return [];
    }

    const response = await api.get(CONFIG.endpoints.departmentStats, {
        requestKey: "admin-dashboard-department-stats",
        cancelPrevious: true
    });

    const items = Array.isArray(response)
        ? response
        : response?.departments || response?.items || [];

    state.departmentStats = items.map(normalizeDepartmentStat).filter(Boolean);
    return state.departmentStats;
}

function bindDashboardEvents() {
    document.querySelectorAll(SELECTORS.refreshButton).forEach((button) => {
        button.addEventListener("click", () => {
            components.withButtonLoading(
                button,
                () => loadDashboard({ showSuccess: true }),
                { text: "กำลังอัปเดต..." }
            ).catch(() => {});
        });
    });

    document.querySelectorAll(SELECTORS.logoutButton).forEach((button) => {
        button.addEventListener("click", async () => {
            const confirmed = await components.confirm({
                title: "ออกจากระบบ",
                message: "ต้องการออกจากระบบ ConnectPro หรือไม่",
                confirmText: "ออกจากระบบ",
                cancelText: "ยกเลิก"
            });

            if (confirmed) await auth.logout();
        });
    });

    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") {
            loadDashboard({ silent: true }).catch(() => {});
        }
    });

    window.addEventListener("beforeunload", stopAutoRefresh, { once: true });
}

function renderDashboard() {
    renderSummary();
    renderRecentContacts();
    renderRecentActivities();
    renderDepartmentStats();
    permissions.apply(document);
}

function renderSummary() {
    setText(SELECTORS.totalContacts, utils.formatNumber(state.summary.totalContacts));
    setText(SELECTORS.activeContacts, utils.formatNumber(state.summary.activeContacts));
    setText(SELECTORS.totalDepartments, utils.formatNumber(state.summary.totalDepartments));
    setText(SELECTORS.totalUsers, utils.formatNumber(state.summary.totalUsers));
}

function renderRecentContacts() {
    document.querySelectorAll(SELECTORS.recentContacts).forEach((container) => {
        const fragment = document.createDocumentFragment();
        state.recentContacts.forEach((contact) => fragment.appendChild(createContactRow(contact)));
        container.replaceChildren(fragment);
    });

    toggleEmptyState(SELECTORS.recentContactsEmpty, state.recentContacts.length === 0);
}

function renderRecentActivities() {
    document.querySelectorAll(SELECTORS.recentActivities).forEach((container) => {
        const fragment = document.createDocumentFragment();
        state.recentActivities.forEach((activity) => fragment.appendChild(createActivityItem(activity)));
        container.replaceChildren(fragment);
    });

    toggleEmptyState(SELECTORS.recentActivitiesEmpty, state.recentActivities.length === 0);
}

function renderDepartmentStats() {
    const maximum = Math.max(...state.departmentStats.map((item) => item.contactCount), 1);

    document.querySelectorAll(SELECTORS.departmentStats).forEach((container) => {
        const fragment = document.createDocumentFragment();

        state.departmentStats.forEach((department) => {
            const percentage = Math.round((department.contactCount / maximum) * 100);
            fragment.appendChild(createDepartmentBar(department, percentage));
        });

        container.replaceChildren(fragment);
    });

    toggleEmptyState(SELECTORS.departmentStatsEmpty, state.departmentStats.length === 0);
}

function createContactRow(contact) {
    const row = document.createElement("article");
    row.className = "cp-user-contact-row";

    const identity = document.createElement("div");
    identity.className = "cp-user-contact-row__identity";

    const avatar = document.createElement("span");
    avatar.className = "cp-glass-avatar";
    avatar.textContent = utils.getInitials(contact.displayName);

    const information = document.createElement("div");
    const name = document.createElement("strong");
    name.textContent = contact.displayName;
    const employee = document.createElement("small");
    employee.textContent = contact.employeeCode || "ไม่มีรหัสพนักงาน";
    information.append(name, employee);
    identity.append(avatar, information);

    const department = document.createElement("span");
    department.textContent = contact.departmentName || "-";

    const time = document.createElement("time");
    time.dateTime = contact.createdAt || "";
    time.textContent = utils.formatRelativeTime(contact.createdAt);

    row.append(identity, department, time);
    return row;
}

function createActivityItem(activity) {
    const item = document.createElement("article");
    item.className = "cp-activity-item";

    const icon = document.createElement("span");
    icon.className = `cp-activity-item__icon cp-activity-item__icon--${activity.type}`;
    icon.setAttribute("aria-hidden", "true");
    icon.textContent = activity.type === "delete" ? "−" : activity.type === "update" ? "↻" : "+";

    const content = document.createElement("div");
    content.className = "cp-activity-item__content";

    const description = document.createElement("p");
    description.textContent = activity.description;

    const meta = document.createElement("small");
    meta.textContent = `${activity.actorName} · ${utils.formatRelativeTime(activity.createdAt)}`;

    content.append(description, meta);
    item.append(icon, content);
    return item;
}

function createDepartmentBar(department, percentage) {
    const item = document.createElement("div");
    item.className = "cp-department-stat";

    const header = document.createElement("div");
    header.className = "cp-department-stat__header";

    const name = document.createElement("span");
    name.textContent = department.name;

    const count = document.createElement("strong");
    count.textContent = utils.formatNumber(department.contactCount);
    header.append(name, count);

    const track = document.createElement("div");
    track.className = "cp-progress";
    track.setAttribute("role", "progressbar");
    track.setAttribute("aria-valuemin", "0");
    track.setAttribute("aria-valuemax", String(maximumValue(state.departmentStats)));
    track.setAttribute("aria-valuenow", String(department.contactCount));

    const bar = document.createElement("span");
    bar.className = "cp-progress__bar";
    bar.style.width = `${utils.clamp(percentage, 0, 100)}%`;
    track.appendChild(bar);

    item.append(header, track);
    return item;
}

function setDashboardLoading(loading) {
    document.querySelectorAll(SELECTORS.page).forEach((element) => {
        element.classList.toggle("is-loading", loading);
        element.setAttribute("aria-busy", String(loading));
    });
}

function updateLastUpdated() {
    const now = new Date();
    document.querySelectorAll(SELECTORS.lastUpdated).forEach((element) => {
        element.dateTime = now.toISOString();
        element.textContent = utils.formatDateTime(now);
    });
}

function startAutoRefresh() {
    stopAutoRefresh();
    state.refreshTimerId = window.setInterval(() => {
        if (document.visibilityState === "visible" && auth.isAuthenticated()) {
            loadDashboard({ silent: true }).catch(() => {});
        }
    }, CONFIG.refreshInterval);
}

function stopAutoRefresh() {
    if (state.refreshTimerId !== null) {
        window.clearInterval(state.refreshTimerId);
        state.refreshTimerId = null;
    }
}

function normalizeSummary(value = {}) {
    return Object.freeze({
        totalContacts: nonNegativeNumber(value.total_contacts ?? value.totalContacts),
        activeContacts: nonNegativeNumber(value.active_contacts ?? value.activeContacts),
        totalDepartments: nonNegativeNumber(value.total_departments ?? value.totalDepartments),
        totalUsers: nonNegativeNumber(value.total_users ?? value.totalUsers)
    });
}

function normalizeContact(value) {
    const id = Number(value?.id || 0);
    if (!Number.isInteger(id) || id < 1) return null;

    return Object.freeze({
        id,
        displayName: String(value.display_name || value.displayName || "ไม่ระบุชื่อ"),
        employeeCode: String(value.employee_code || value.employeeCode || ""),
        departmentName: String(value.department_name || value.departmentName || ""),
        createdAt: value.created_at || value.createdAt || null
    });
}

function normalizeActivity(value) {
    const id = Number(value?.id || 0);
    if (!Number.isInteger(id) || id < 1) return null;

    const allowedTypes = ["create", "update", "delete"];
    const type = String(value.action || value.type || "update").toLowerCase();

    return Object.freeze({
        id,
        type: allowedTypes.includes(type) ? type : "update",
        description: String(value.description || value.message || "มีการเปลี่ยนแปลงข้อมูล"),
        actorName: String(value.actor_name || value.actorName || "ระบบ"),
        createdAt: value.created_at || value.createdAt || null
    });
}

function normalizeDepartmentStat(value) {
    const id = Number(value?.id || value?.department_id || 0);
    if (!Number.isInteger(id) || id < 1) return null;

    return Object.freeze({
        id,
        name: String(value.name || value.department_name || "ไม่ระบุแผนก"),
        contactCount: nonNegativeNumber(value.contact_count ?? value.contactCount)
    });
}

function createEmptySummary() {
    return Object.freeze({
        totalContacts: 0,
        activeContacts: 0,
        totalDepartments: 0,
        totalUsers: 0
    });
}

function nonNegativeNumber(value) {
    return Math.max(0, utils.toNumber(value, 0));
}

function maximumValue(items) {
    return Math.max(...items.map((item) => item.contactCount), 1);
}

function setText(selector, value) {
    document.querySelectorAll(selector).forEach((element) => {
        element.textContent = String(value);
    });
}

function toggleEmptyState(selector, visible) {
    document.querySelectorAll(selector).forEach((element) => {
        element.hidden = !visible;
    });
}

function handleDashboardError(error, fallbackMessage) {
    if (error instanceof ApiError && error.isCancelled) return;

    console.error("[ConnectPro Admin Dashboard]", error);
    components.toast.error(error?.message || fallbackMessage);
}

document.addEventListener("DOMContentLoaded", initializeDashboard, { once: true });

export default Object.freeze({
    init: initializeDashboard,
    load: loadDashboard,
    startAutoRefresh,
    stopAutoRefresh
});
