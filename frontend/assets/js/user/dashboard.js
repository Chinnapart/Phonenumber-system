/**
 * ConnectPro User Dashboard
 * File: frontend/assets/js/user/dashboard.js
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
        summary: "user/dashboard/summary.php",
        favorites: "user/dashboard/favorites.php",
        recentContacts: "user/dashboard/recent-contacts.php",
        departments: "user/dashboard/departments.php",
        toggleFavorite: "user/favorites/toggle.php"
    }),
    refreshInterval: 5 * 60 * 1000,
    itemLimit: 8
});

const SELECTORS = Object.freeze({
    page: "[data-user-dashboard]",
    refreshButton: "[data-dashboard-refresh]",
    logoutButton: "[data-logout]",
    lastUpdated: "[data-dashboard-last-updated]",
    totalContacts: "[data-stat=\"total-contacts\"]",
    totalDepartments: "[data-stat=\"total-departments\"]",
    favoriteCount: "[data-stat=\"favorite-contacts\"]",
    recentlyViewedCount: "[data-stat=\"recently-viewed\"]",
    favorites: "[data-dashboard-favorites]",
    favoritesEmpty: "[data-dashboard-favorites-empty]",
    recentContacts: "[data-dashboard-recent-contacts]",
    recentContactsEmpty: "[data-dashboard-recent-empty]",
    departments: "[data-dashboard-departments]",
    departmentsEmpty: "[data-dashboard-departments-empty]",
    searchForm: "[data-dashboard-search-form]",
    searchInput: "[data-dashboard-search]"
});

const state = {
    initialized: false,
    loading: false,
    refreshTimerId: null,
    summary: createEmptySummary(),
    favorites: [],
    recentContacts: [],
    departments: []
};

async function initializeDashboard() {
    if (state.initialized) return;
    state.initialized = true;

    components.init();
    auth.init();

    try {
        const user = await auth.requireAuth({ roles: ["admin", "user"] });
        if (!user) return;

        permissions.init();
        permissions.authorize(PERMISSIONS.DASHBOARD.VIEW);
        auth.hydrateUserElements(document, user);
        bindEvents();

        await Promise.allSettled([
            notifications.init({ showToastForNew: true }),
            loadDashboard()
        ]);

        startAutoRefresh();
    } catch (error) {
        handleError(error, "ไม่สามารถเริ่มต้นหน้า Dashboard ได้");
    }
}

export async function loadDashboard(options = {}) {
    if (state.loading) return;

    state.loading = true;
    setLoading(true);

    try {
        const results = await Promise.allSettled([
            loadSummary(),
            loadFavorites(),
            loadRecentContacts(),
            loadDepartments()
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
            components.toast.success("อัปเดต Dashboard แล้ว", { duration: 2000 });
        }
    } catch (error) {
        if (!options.silent) handleError(error, "โหลด Dashboard ไม่สำเร็จ");
        throw error;
    } finally {
        state.loading = false;
        setLoading(false);
    }
}

async function loadSummary() {
    const response = await api.get(CONFIG.endpoints.summary, {
        requestKey: "user-dashboard-summary",
        cancelPrevious: true
    });

    state.summary = normalizeSummary(response?.summary || response);
    return state.summary;
}

async function loadFavorites() {
    if (!permissions.has(PERMISSIONS.CONTACTS.VIEW)) {
        state.favorites = [];
        return [];
    }

    const response = await api.get(CONFIG.endpoints.favorites, {
        query: { limit: CONFIG.itemLimit },
        requestKey: "user-dashboard-favorites",
        cancelPrevious: true
    });

    state.favorites = extractItems(response, "favorites")
        .map(normalizeContact)
        .filter(Boolean);
    return state.favorites;
}

async function loadRecentContacts() {
    if (!permissions.has(PERMISSIONS.CONTACTS.VIEW)) {
        state.recentContacts = [];
        return [];
    }

    const response = await api.get(CONFIG.endpoints.recentContacts, {
        query: { limit: CONFIG.itemLimit },
        requestKey: "user-dashboard-recent-contacts",
        cancelPrevious: true
    });

    state.recentContacts = extractItems(response, "contacts")
        .map(normalizeContact)
        .filter(Boolean);
    return state.recentContacts;
}

async function loadDepartments() {
    if (!permissions.has(PERMISSIONS.DEPARTMENTS.VIEW)) {
        state.departments = [];
        return [];
    }

    const response = await api.get(CONFIG.endpoints.departments, {
        query: { limit: CONFIG.itemLimit },
        requestKey: "user-dashboard-departments",
        cancelPrevious: true
    });

    state.departments = extractItems(response, "departments")
        .map(normalizeDepartment)
        .filter(Boolean);
    return state.departments;
}

function bindEvents() {
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
        button.addEventListener("click", handleLogout);
    });

    document.querySelector(SELECTORS.searchForm)?.addEventListener("submit", (event) => {
        event.preventDefault();
        const keyword = utils.normalizeText(
            document.querySelector(SELECTORS.searchInput)?.value
        );

        if (!keyword) {
            components.toast.warning("กรุณากรอกคำค้นหา");
            return;
        }

        const query = utils.buildQueryString({ search: keyword });
        window.location.assign(
            `/connectpro/frontend/pages/user/contacts.html${query}`
        );
    });

    document.addEventListener("click", handleDelegatedClick);

    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible" && auth.isAuthenticated()) {
            loadDashboard({ silent: true }).catch(() => {});
        }
    });

    window.addEventListener("beforeunload", stopAutoRefresh, { once: true });
}

async function handleDelegatedClick(event) {
    const favoriteButton = event.target.closest("[data-favorite-toggle]");
    const contactLink = event.target.closest("[data-contact-open]");
    const departmentLink = event.target.closest("[data-department-open]");

    try {
        if (favoriteButton) {
            await toggleFavorite(
                utils.toInteger(favoriteButton.dataset.favoriteToggle),
                favoriteButton
            );
        } else if (contactLink) {
            navigateToContact(utils.toInteger(contactLink.dataset.contactOpen));
        } else if (departmentLink) {
            navigateToDepartment(
                utils.toInteger(departmentLink.dataset.departmentOpen)
            );
        }
    } catch (error) {
        handleError(error, "ดำเนินการไม่สำเร็จ");
    }
}

export async function toggleFavorite(contactId, button = null) {
    assertValidId(contactId, "Contact");

    const response = await api.post(CONFIG.endpoints.toggleFavorite, {
        contact_id: contactId
    }, {
        requestKey: `favorite-toggle-${contactId}`
    });

    const isFavorite = utils.toBoolean(
        response?.is_favorite ?? response?.isFavorite,
        true
    );

    updateFavoriteButton(button, isFavorite);
    components.toast.success(
        isFavorite ? "เพิ่มในรายการโปรดแล้ว" : "นำออกจากรายการโปรดแล้ว",
        { duration: 2000 }
    );

    await Promise.allSettled([loadSummary(), loadFavorites()]);
    renderSummary();
    renderFavorites();
    return isFavorite;
}

function renderDashboard() {
    renderSummary();
    renderFavorites();
    renderRecentContacts();
    renderDepartments();
    permissions.apply(document);
}

function renderSummary() {
    setText(SELECTORS.totalContacts, utils.formatNumber(state.summary.totalContacts));
    setText(SELECTORS.totalDepartments, utils.formatNumber(state.summary.totalDepartments));
    setText(SELECTORS.favoriteCount, utils.formatNumber(state.summary.favoriteContacts));
    setText(SELECTORS.recentlyViewedCount, utils.formatNumber(state.summary.recentlyViewed));
}

function renderFavorites() {
    renderContactCollection(SELECTORS.favorites, state.favorites);
    setHidden(SELECTORS.favoritesEmpty, state.favorites.length > 0);
}

function renderRecentContacts() {
    renderContactCollection(SELECTORS.recentContacts, state.recentContacts);
    setHidden(SELECTORS.recentContactsEmpty, state.recentContacts.length > 0);
}

function renderContactCollection(selector, contacts) {
    document.querySelectorAll(selector).forEach((container) => {
        const fragment = document.createDocumentFragment();
        contacts.forEach((contact) => fragment.appendChild(createContactCard(contact)));
        container.replaceChildren(fragment);
    });
}

function createContactCard(contact) {
    const card = document.createElement("article");
    card.className = "cp-contact-card";

    const header = document.createElement("div");
    header.className = "cp-contact-card__header";

    const identity = document.createElement("button");
    identity.type = "button";
    identity.className = "cp-contact-card__identity";
    identity.dataset.contactOpen = String(contact.id);

    const avatar = document.createElement("span");
    avatar.className = "cp-glass-avatar";
    avatar.textContent = utils.getInitials(contact.displayName);

    const nameGroup = document.createElement("span");
    const name = document.createElement("strong");
    name.textContent = contact.displayName;
    const employee = document.createElement("small");
    employee.textContent = contact.employeeCode || "ไม่มีรหัสพนักงาน";
    nameGroup.append(name, employee);
    identity.append(avatar, nameGroup);

    const favoriteButton = document.createElement("button");
    favoriteButton.type = "button";
    favoriteButton.className = "cp-favorite-button";
    favoriteButton.dataset.favoriteToggle = String(contact.id);
    updateFavoriteButton(favoriteButton, contact.isFavorite);
    header.append(identity, favoriteButton);

    const details = document.createElement("div");
    details.className = "cp-contact-card__details";
    details.append(
        createDetail("เบอร์ต่อ", contact.extensionNumber || "-"),
        createDetail("แผนก", contact.departmentName || "-"),
        createDetail("สถานที่", contact.locationName || "-")
    );

    const actions = document.createElement("div");
    actions.className = "cp-contact-card__actions";

    const viewButton = document.createElement("button");
    viewButton.type = "button";
    viewButton.className = "cp-button cp-button--primary cp-button--small";
    viewButton.textContent = "ดูรายละเอียด";
    viewButton.dataset.contactOpen = String(contact.id);
    actions.appendChild(viewButton);

    if (contact.extensionNumber) {
        const copyButton = document.createElement("button");
        copyButton.type = "button";
        copyButton.className = "cp-button cp-button--secondary cp-button--small";
        copyButton.textContent = "คัดลอกเบอร์ต่อ";
        copyButton.dataset.copyValue = contact.extensionNumber;
        actions.appendChild(copyButton);
    }

    card.append(header, details, actions);
    components.initCopyButtons(card);
    return card;
}

function createDetail(labelText, value) {
    const group = document.createElement("div");
    const label = document.createElement("small");
    label.textContent = labelText;
    const content = document.createElement("span");
    content.textContent = value;
    group.append(label, content);
    return group;
}

function renderDepartments() {
    document.querySelectorAll(SELECTORS.departments).forEach((container) => {
        const fragment = document.createDocumentFragment();

        state.departments.forEach((department) => {
            const card = document.createElement("button");
            card.type = "button";
            card.className = "cp-user-department-card";
            card.dataset.departmentOpen = String(department.id);

            const name = document.createElement("strong");
            name.textContent = department.name;
            const count = document.createElement("span");
            count.textContent = `${utils.formatNumber(department.contactCount)} รายชื่อ`;
            const description = document.createElement("small");
            description.textContent = department.description || "ดูรายชื่อในแผนก";

            card.append(name, count, description);
            fragment.appendChild(card);
        });

        container.replaceChildren(fragment);
    });

    setHidden(SELECTORS.departmentsEmpty, state.departments.length > 0);
}

function updateFavoriteButton(button, isFavorite) {
    if (!(button instanceof HTMLElement)) return;
    button.classList.toggle("is-active", isFavorite);
    button.setAttribute("aria-pressed", String(isFavorite));
    button.setAttribute(
        "aria-label",
        isFavorite ? "นำออกจากรายการโปรด" : "เพิ่มในรายการโปรด"
    );
    button.textContent = isFavorite ? "★" : "☆";
}

function navigateToContact(contactId) {
    assertValidId(contactId, "Contact");
    window.location.assign(
        `/connectpro/frontend/pages/user/contact-detail.html?id=${contactId}`
    );
}

function navigateToDepartment(departmentId) {
    assertValidId(departmentId, "Department");
    window.location.assign(
        `/connectpro/frontend/pages/user/contacts.html?department_id=${departmentId}`
    );
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

function updateLastUpdated() {
    const now = new Date();
    document.querySelectorAll(SELECTORS.lastUpdated).forEach((element) => {
        element.dateTime = now.toISOString();
        element.textContent = utils.formatDateTime(now);
    });
}

function normalizeSummary(value = {}) {
    return Object.freeze({
        totalContacts: nonNegativeNumber(value.total_contacts ?? value.totalContacts),
        totalDepartments: nonNegativeNumber(value.total_departments ?? value.totalDepartments),
        favoriteContacts: nonNegativeNumber(value.favorite_contacts ?? value.favoriteContacts),
        recentlyViewed: nonNegativeNumber(value.recently_viewed ?? value.recentlyViewed)
    });
}

function normalizeContact(value) {
    const id = Number(value?.id || value?.contact_id || 0);
    if (!Number.isInteger(id) || id < 1) return null;

    return Object.freeze({
        id,
        employeeCode: String(value.employee_code || value.employeeCode || ""),
        displayName: String(
            value.display_name || value.displayName || "ไม่ระบุชื่อ"
        ),
        extensionNumber: String(
            value.extension_number || value.extensionNumber || ""
        ),
        departmentName: String(
            value.department_name || value.departmentName || ""
        ),
        locationName: String(value.location_name || value.locationName || ""),
        isFavorite: utils.toBoolean(
            value.is_favorite ?? value.isFavorite,
            false
        )
    });
}

function normalizeDepartment(value) {
    const id = Number(value?.id || value?.department_id || 0);
    if (!Number.isInteger(id) || id < 1) return null;

    return Object.freeze({
        id,
        name: String(value.name || value.department_name || "ไม่ระบุแผนก"),
        description: String(value.description || ""),
        contactCount: nonNegativeNumber(
            value.contact_count ?? value.contactCount
        )
    });
}

function createEmptySummary() {
    return Object.freeze({
        totalContacts: 0,
        totalDepartments: 0,
        favoriteContacts: 0,
        recentlyViewed: 0
    });
}

function extractItems(value, key) {
    if (Array.isArray(value)) return value;
    return value?.[key] || value?.items || [];
}

function nonNegativeNumber(value) {
    return Math.max(0, utils.toNumber(value, 0));
}

function assertValidId(id, label) {
    if (!Number.isInteger(id) || id < 1) {
        throw new TypeError(`${label} ID ไม่ถูกต้อง`);
    }
}

function setLoading(loading) {
    document.querySelectorAll(SELECTORS.page).forEach((element) => {
        element.classList.toggle("is-loading", loading);
        element.setAttribute("aria-busy", String(loading));
    });
}

function setText(selector, value) {
    document.querySelectorAll(selector).forEach((element) => {
        element.textContent = String(value);
    });
}

function setHidden(selector, hidden) {
    document.querySelectorAll(selector).forEach((element) => {
        element.hidden = hidden;
    });
}

async function handleLogout() {
    const confirmed = await components.confirm({
        title: "ออกจากระบบ",
        message: "ต้องการออกจากระบบ ConnectPro หรือไม่",
        confirmText: "ออกจากระบบ",
        cancelText: "ยกเลิก"
    });

    if (confirmed) await auth.logout();
}

function handleError(error, fallback) {
    if (error instanceof ApiError && error.isCancelled) return;
    console.error("[ConnectPro User Dashboard]", error);
    components.toast.error(error?.message || fallback);
}

document.addEventListener("DOMContentLoaded", initializeDashboard, {
    once: true
});

export default Object.freeze({
    init: initializeDashboard,
    load: loadDashboard,
    toggleFavorite,
    startAutoRefresh,
    stopAutoRefresh
});
