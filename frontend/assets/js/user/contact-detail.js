/**
 * ConnectPro User Contact Detail
 * File: frontend/assets/js/user/contact-detail.js
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
        detail: "user/contacts/detail.php",
        related: "user/contacts/related.php",
        toggleFavorite: "user/favorites/toggle.php"
    }),
    contactsPage: "/connectpro/frontend/pages/user/contacts.html",
    relatedLimit: 6
});

const SELECTORS = Object.freeze({
    page: "[data-user-contact-detail]",
    loading: "[data-contact-detail-loading]",
    error: "[data-contact-detail-error]",
    notFound: "[data-contact-detail-not-found]",
    content: "[data-contact-detail-content]",
    backButton: "[data-contact-back]",
    refreshButton: "[data-contact-detail-refresh]",
    logoutButton: "[data-logout]",
    favoriteButton: "[data-contact-favorite]",
    avatar: "[data-contact-avatar]",
    displayName: "[data-contact-field=\"displayName\"]",
    employeeCode: "[data-contact-field=\"employeeCode\"]",
    extensionNumber: "[data-contact-field=\"extensionNumber\"]",
    mobileNumber: "[data-contact-field=\"mobileNumber\"]",
    email: "[data-contact-field=\"email\"]",
    departmentName: "[data-contact-field=\"departmentName\"]",
    locationName: "[data-contact-field=\"locationName\"]",
    ipAddress: "[data-contact-field=\"ipAddress\"]",
    position: "[data-contact-field=\"position\"]",
    updatedAt: "[data-contact-field=\"updatedAt\"]",
    relatedList: "[data-related-contacts]",
    relatedEmpty: "[data-related-contacts-empty]"
});

const state = {
    initialized: false,
    loading: false,
    contactId: null,
    contact: null,
    relatedContacts: []
};

async function initializeContactDetail() {
    if (state.initialized) return;
    state.initialized = true;

    components.init();
    auth.init();

    try {
        const user = await auth.requireAuth({ roles: ["admin", "user"] });
        if (!user) return;

        permissions.init();
        permissions.authorize(PERMISSIONS.CONTACTS.VIEW);
        auth.hydrateUserElements(document, user);

        state.contactId = getContactIdFromUrl();
        bindEvents();

        await Promise.allSettled([
            notifications.init({ showToastForNew: true }),
            loadContactDetail()
        ]);
    } catch (error) {
        handleError(error, "ไม่สามารถเริ่มต้นหน้ารายละเอียดผู้ติดต่อได้");
    }
}

export async function loadContactDetail(options = {}) {
    if (state.loading) return;
    assertValidId(state.contactId);

    state.loading = true;
    setLoading(true);
    hideError();
    setHidden(SELECTORS.notFound, true);

    try {
        const response = await api.get(CONFIG.endpoints.detail, {
            query: { id: state.contactId },
            requestKey: `user-contact-detail-${state.contactId}`,
            cancelPrevious: true
        });

        state.contact = normalizeContact(response?.contact || response);

        if (!state.contact) {
            throw new ApiError("ไม่พบข้อมูลผู้ติดต่อ", {
                status: 404,
                code: "CONTACT_NOT_FOUND"
            });
        }

        renderContact();
        await loadRelatedContacts();
        setHidden(SELECTORS.content, false);

        if (options.showSuccess) {
            components.toast.success("อัปเดตข้อมูลแล้ว", { duration: 2000 });
        }
    } catch (error) {
        state.contact = null;
        state.relatedContacts = [];
        setHidden(SELECTORS.content, true);

        if (error instanceof ApiError && error.status === 404) {
            setHidden(SELECTORS.notFound, false);
        } else if (!(error instanceof ApiError && error.isCancelled)) {
            showError(error.message || "โหลดข้อมูลผู้ติดต่อไม่สำเร็จ");
            if (!options.silent) {
                handleError(error, "โหลดข้อมูลผู้ติดต่อไม่สำเร็จ");
            }
        }
    } finally {
        state.loading = false;
        setLoading(false);
    }
}

async function loadRelatedContacts() {
    if (!state.contact?.departmentId) {
        state.relatedContacts = [];
        renderRelatedContacts();
        return [];
    }

    try {
        const response = await api.get(CONFIG.endpoints.related, {
            query: {
                contact_id: state.contact.id,
                department_id: state.contact.departmentId,
                limit: CONFIG.relatedLimit
            },
            requestKey: `user-related-contacts-${state.contact.id}`,
            cancelPrevious: true
        });

        state.relatedContacts = extractItems(response, "contacts")
            .map(normalizeContact)
            .filter((contact) => contact && contact.id !== state.contact.id);
        renderRelatedContacts();
        return state.relatedContacts;
    } catch (error) {
        state.relatedContacts = [];
        renderRelatedContacts();
        console.error("[ConnectPro Related Contacts]", error);
        return [];
    }
}

function bindEvents() {
    document.querySelectorAll(SELECTORS.backButton).forEach((button) => {
        button.addEventListener("click", navigateBack);
    });

    document.querySelectorAll(SELECTORS.refreshButton).forEach((button) => {
        button.addEventListener("click", () => {
            components.withButtonLoading(
                button,
                () => loadContactDetail({ showSuccess: true }),
                { text: "กำลังอัปเดต..." }
            ).catch(() => {});
        });
    });

    document.querySelectorAll(SELECTORS.favoriteButton).forEach((button) => {
        button.addEventListener("click", () => toggleFavorite(button));
    });

    document.querySelectorAll(SELECTORS.logoutButton).forEach((button) => {
        button.addEventListener("click", handleLogout);
    });

    document.addEventListener("click", (event) => {
        const relatedButton = event.target.closest("[data-related-contact-open]");

        if (relatedButton) {
            navigateToContact(
                utils.toInteger(relatedButton.dataset.relatedContactOpen)
            );
        }
    });
}

export async function toggleFavorite(button = null) {
    if (!state.contact) return false;

    const response = await api.post(CONFIG.endpoints.toggleFavorite, {
        contact_id: state.contact.id
    }, {
        requestKey: `user-favorite-${state.contact.id}`
    });

    const isFavorite = utils.toBoolean(
        response?.is_favorite ?? response?.isFavorite,
        !state.contact.isFavorite
    );

    state.contact = Object.freeze({
        ...state.contact,
        isFavorite
    });

    updateFavoriteButtons(isFavorite, button);
    components.toast.success(
        isFavorite ? "เพิ่มในรายการโปรดแล้ว" : "นำออกจากรายการโปรดแล้ว",
        { duration: 2000 }
    );

    return isFavorite;
}

function renderContact() {
    const contact = state.contact;
    if (!contact) return;

    setText(SELECTORS.avatar, utils.getInitials(contact.displayName));
    setText(SELECTORS.displayName, contact.displayName);
    setText(SELECTORS.employeeCode, contact.employeeCode || "-");
    setText(SELECTORS.extensionNumber, contact.extensionNumber || "-");
    setText(SELECTORS.mobileNumber, contact.mobileNumber || "-");
    setText(SELECTORS.email, contact.email || "-");
    setText(SELECTORS.departmentName, contact.departmentName || "-");
    setText(SELECTORS.locationName, contact.locationName || "-");
    setText(SELECTORS.ipAddress, contact.ipAddress || "-");
    setText(SELECTORS.position, contact.position || "-");
    setText(
        SELECTORS.updatedAt,
        contact.updatedAt ? utils.formatDateTime(contact.updatedAt) : "-"
    );

    updateCopyTargets();
    updateFavoriteButtons(contact.isFavorite);
    permissions.apply(document);
}

function updateCopyTargets() {
    const copyValues = {
        extensionNumber: state.contact?.extensionNumber,
        mobileNumber: state.contact?.mobileNumber,
        email: state.contact?.email,
        ipAddress: state.contact?.ipAddress
    };

    Object.entries(copyValues).forEach(([field, value]) => {
        document.querySelectorAll(`[data-copy-contact="${field}"]`).forEach((button) => {
            button.dataset.copyValue = value || "";
            button.disabled = !value;
        });
    });

    components.initCopyButtons(document);
}

function updateFavoriteButtons(isFavorite, sourceButton = null) {
    const buttons = new Set([
        ...document.querySelectorAll(SELECTORS.favoriteButton),
        ...(sourceButton ? [sourceButton] : [])
    ]);

    buttons.forEach((button) => {
        button.classList.toggle("is-active", isFavorite);
        button.setAttribute("aria-pressed", String(isFavorite));
        button.setAttribute(
            "aria-label",
            isFavorite ? "นำออกจากรายการโปรด" : "เพิ่มในรายการโปรด"
        );

        const label = button.querySelector("[data-favorite-label]");
        if (label) {
            label.textContent = isFavorite ? "รายการโปรด" : "เพิ่มรายการโปรด";
        } else {
            button.textContent = isFavorite ? "★ รายการโปรด" : "☆ เพิ่มรายการโปรด";
        }
    });
}

function renderRelatedContacts() {
    document.querySelectorAll(SELECTORS.relatedList).forEach((container) => {
        const fragment = document.createDocumentFragment();
        state.relatedContacts.forEach((contact) => {
            fragment.appendChild(createRelatedContactCard(contact));
        });
        container.replaceChildren(fragment);
    });

    setHidden(SELECTORS.relatedEmpty, state.relatedContacts.length > 0);
}

function createRelatedContactCard(contact) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "cp-contact-card cp-related-contact-card";
    button.dataset.relatedContactOpen = String(contact.id);

    const avatar = document.createElement("span");
    avatar.className = "cp-glass-avatar";
    avatar.textContent = utils.getInitials(contact.displayName);

    const content = document.createElement("span");
    content.className = "cp-related-contact-card__content";
    const name = document.createElement("strong");
    name.textContent = contact.displayName;
    const extension = document.createElement("small");
    extension.textContent = contact.extensionNumber
        ? `เบอร์ต่อ ${contact.extensionNumber}`
        : "ไม่มีเบอร์ต่อ";
    const location = document.createElement("small");
    location.textContent = contact.locationName || "ไม่ระบุสถานที่";
    content.append(name, extension, location);

    button.append(avatar, content);
    return button;
}

function getContactIdFromUrl() {
    const params = utils.getQueryParams();
    const contactId = utils.toInteger(params.id, 0);
    assertValidId(contactId);
    return contactId;
}

function navigateBack() {
    if (window.history.length > 1 && document.referrer) {
        const referrer = new URL(document.referrer, window.location.origin);

        if (referrer.origin === window.location.origin) {
            window.history.back();
            return;
        }
    }

    window.location.assign(CONFIG.contactsPage);
}

function navigateToContact(contactId) {
    assertValidId(contactId);
    window.location.assign(
        `/connectpro/frontend/pages/user/contact-detail.html?id=${contactId}`
    );
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
        mobileNumber: String(value.mobile_number || value.mobileNumber || ""),
        email: String(value.email || ""),
        position: String(value.position || value.job_title || value.jobTitle || ""),
        departmentId: value.department_id || value.departmentId || null,
        departmentName: String(
            value.department_name || value.departmentName || ""
        ),
        locationId: value.location_id || value.locationId || null,
        locationName: String(value.location_name || value.locationName || ""),
        ipAddress: String(value.ip_address || value.ipAddress || ""),
        isFavorite: utils.toBoolean(
            value.is_favorite ?? value.isFavorite,
            false
        ),
        updatedAt: value.updated_at || value.updatedAt || null
    });
}

function extractItems(value, key) {
    if (Array.isArray(value)) return value;
    return value?.[key] || value?.items || [];
}

function assertValidId(contactId) {
    if (!Number.isInteger(contactId) || contactId < 1) {
        throw new TypeError("Contact ID ไม่ถูกต้อง");
    }
}

function setLoading(loading) {
    document.querySelectorAll(SELECTORS.page).forEach((element) => {
        element.classList.toggle("is-loading", loading);
        element.setAttribute("aria-busy", String(loading));
    });

    document.querySelectorAll(SELECTORS.loading).forEach((element) => {
        element.hidden = !loading;
    });
}

function setText(selector, value) {
    document.querySelectorAll(selector).forEach((element) => {
        element.textContent = String(value ?? "-");
    });
}

function setHidden(selector, hidden) {
    document.querySelectorAll(selector).forEach((element) => {
        element.hidden = hidden;
    });
}

function showError(message) {
    document.querySelectorAll(SELECTORS.error).forEach((element) => {
        element.textContent = String(message);
        element.hidden = false;
    });
}

function hideError() {
    document.querySelectorAll(SELECTORS.error).forEach((element) => {
        element.textContent = "";
        element.hidden = true;
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
    console.error("[ConnectPro User Contact Detail]", error);
    components.toast.error(error?.message || fallback);
}

document.addEventListener("DOMContentLoaded", initializeContactDetail, {
    once: true
});

export default Object.freeze({
    init: initializeContactDetail,
    load: loadContactDetail,
    toggleFavorite,
    back: navigateBack
});
