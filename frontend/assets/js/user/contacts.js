/**
 * ConnectPro User Contacts
 * File: frontend/assets/js/user/contacts.js
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
        list: "user/contacts/list.php",
        detail: "user/contacts/detail.php",
        departments: "user/departments/list.php",
        locations: "user/locations/list.php",
        toggleFavorite: "user/favorites/toggle.php"
    }),
    pageSize: 20,
    searchDelay: 350
});

const SELECTORS = Object.freeze({
    page: "[data-user-contacts]",
    search: "[data-contact-search]",
    departmentFilter: "[data-contact-department-filter]",
    locationFilter: "[data-contact-location-filter]",
    favoriteFilter: "[data-contact-favorite-filter]",
    sort: "[data-contact-sort]",
    pageSize: "[data-contact-page-size]",
    list: "[data-contacts-list]",
    tableBody: "[data-contacts-table-body]",
    empty: "[data-contacts-empty]",
    loading: "[data-contacts-loading]",
    error: "[data-contacts-error]",
    total: "[data-contacts-total]",
    summary: "[data-contacts-page-summary]",
    pagination: "[data-contacts-pagination]",
    resetButton: "[data-contacts-reset]",
    refreshButton: "[data-contacts-refresh]",
    logoutButton: "[data-logout]"
});

const state = {
    initialized: false,
    loading: false,
    contacts: [],
    departments: [],
    locations: [],
    query: {
        search: "",
        departmentId: "",
        locationId: "",
        favoriteOnly: false,
        sort: "display_name",
        direction: "asc",
        page: 1,
        limit: CONFIG.pageSize
    },
    pagination: {
        page: 1,
        limit: CONFIG.pageSize,
        total: 0,
        totalPages: 1
    }
};

async function initializeContacts() {
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
        restoreQueryFromUrl();
        syncControls();
        bindEvents();

        await Promise.allSettled([
            loadFilterOptions(),
            notifications.init({ showToastForNew: true })
        ]);

        await loadContacts();
    } catch (error) {
        handleError(error, "ไม่สามารถเริ่มต้นหน้ารายชื่อผู้ติดต่อได้");
    }
}

export async function loadContacts(options = {}) {
    if (state.loading) return;

    state.loading = true;
    setLoading(true);
    hideError();

    try {
        const response = await api.get(CONFIG.endpoints.list, {
            query: {
                search: state.query.search,
                department_id: state.query.departmentId,
                location_id: state.query.locationId,
                favorite_only: state.query.favoriteOnly ? 1 : undefined,
                sort: state.query.sort,
                direction: state.query.direction,
                page: state.query.page,
                limit: state.query.limit
            },
            requestKey: "user-contacts-list",
            cancelPrevious: true,
            returnMeta: true
        });

        const payload = response.data || {};
        const items = Array.isArray(payload)
            ? payload
            : payload.contacts || payload.items || [];

        state.contacts = items.map(normalizeContact).filter(Boolean);
        state.pagination = normalizePagination(
            response.meta || payload.meta || payload.pagination,
            state.contacts.length
        );
        state.query.page = state.pagination.page;

        renderContacts();
        syncQueryToUrl();

        if (options.showSuccess) {
            components.toast.success("อัปเดตรายชื่อแล้ว", { duration: 2000 });
        }
    } catch (error) {
        if (!(error instanceof ApiError && error.isCancelled)) {
            showError(error.message || "โหลดรายชื่อผู้ติดต่อไม่สำเร็จ");
            if (!options.silent) handleError(error, "โหลดรายชื่อผู้ติดต่อไม่สำเร็จ");
        }
    } finally {
        state.loading = false;
        setLoading(false);
    }
}

async function loadFilterOptions() {
    const [departmentsResult, locationsResult] = await Promise.allSettled([
        api.get(CONFIG.endpoints.departments, {
            query: { status: "active", limit: 500 },
            requestKey: "user-contact-departments"
        }),
        api.get(CONFIG.endpoints.locations, {
            query: { status: "active", limit: 500 },
            requestKey: "user-contact-locations"
        })
    ]);

    if (departmentsResult.status === "fulfilled") {
        state.departments = extractItems(departmentsResult.value, "departments")
            .map(normalizeOption)
            .filter(Boolean);
    }

    if (locationsResult.status === "fulfilled") {
        state.locations = extractItems(locationsResult.value, "locations")
            .map(normalizeOption)
            .filter(Boolean);
    }

    populateSelect(
        SELECTORS.departmentFilter,
        state.departments,
        "ทุกแผนก",
        state.query.departmentId
    );
    populateSelect(
        SELECTORS.locationFilter,
        state.locations,
        "ทุกสถานที่",
        state.query.locationId
    );
}

function bindEvents() {
    const searchHandler = utils.debounce((value) => {
        state.query.search = utils.normalizeText(value);
        state.query.page = 1;
        loadContacts().catch(() => {});
    }, CONFIG.searchDelay);

    document.querySelector(SELECTORS.search)?.addEventListener("input", (event) => {
        searchHandler(event.target.value);
    });

    bindFilter(SELECTORS.departmentFilter, "departmentId");
    bindFilter(SELECTORS.locationFilter, "locationId");

    document.querySelector(SELECTORS.favoriteFilter)?.addEventListener("change", (event) => {
        state.query.favoriteOnly = event.target.checked;
        state.query.page = 1;
        loadContacts().catch(() => {});
    });

    document.querySelector(SELECTORS.sort)?.addEventListener("change", (event) => {
        const [sort, direction = "asc"] = event.target.value.split(":");
        state.query.sort = sort;
        state.query.direction = direction;
        state.query.page = 1;
        loadContacts().catch(() => {});
    });

    document.querySelector(SELECTORS.pageSize)?.addEventListener("change", (event) => {
        state.query.limit = utils.clamp(
            utils.toInteger(event.target.value, CONFIG.pageSize),
            10,
            100
        );
        state.query.page = 1;
        loadContacts().catch(() => {});
    });

    document.querySelectorAll(SELECTORS.resetButton).forEach((button) => {
        button.addEventListener("click", resetFilters);
    });

    document.querySelectorAll(SELECTORS.refreshButton).forEach((button) => {
        button.addEventListener("click", () => {
            components.withButtonLoading(
                button,
                () => loadContacts({ showSuccess: true }),
                { text: "กำลังอัปเดต..." }
            ).catch(() => {});
        });
    });

    document.querySelectorAll(SELECTORS.logoutButton).forEach((button) => {
        button.addEventListener("click", handleLogout);
    });

    document.addEventListener("click", handleDelegatedClick);
}

function bindFilter(selector, key) {
    document.querySelector(selector)?.addEventListener("change", (event) => {
        state.query[key] = event.target.value;
        state.query.page = 1;
        loadContacts().catch(() => {});
    });
}

async function handleDelegatedClick(event) {
    const favoriteButton = event.target.closest("[data-favorite-toggle]");
    const detailButton = event.target.closest("[data-contact-detail]");
    const pageButton = event.target.closest("[data-page]");

    try {
        if (favoriteButton) {
            await toggleFavorite(
                utils.toInteger(favoriteButton.dataset.favoriteToggle),
                favoriteButton
            );
        } else if (detailButton) {
            await showContactDetail(
                utils.toInteger(detailButton.dataset.contactDetail)
            );
        } else if (pageButton && !pageButton.disabled) {
            state.query.page = utils.toInteger(pageButton.dataset.page, 1);
            await loadContacts();
        }
    } catch (error) {
        handleError(error, "ดำเนินการไม่สำเร็จ");
    }
}

export async function showContactDetail(contactId) {
    assertValidId(contactId);

    const response = await api.get(CONFIG.endpoints.detail, {
        query: { id: contactId },
        requestKey: `user-contact-detail-${contactId}`
    });
    const contact = normalizeContact(response?.contact || response);

    if (!contact) throw new Error("ไม่พบข้อมูลผู้ติดต่อ");

    const content = createContactDetail(contact);
    components.openModal({
        title: "รายละเอียดผู้ติดต่อ",
        content,
        className: "cp-glass-modal--large"
    });
    components.initCopyButtons(content);
}

export async function toggleFavorite(contactId, button = null) {
    assertValidId(contactId);

    const response = await api.post(CONFIG.endpoints.toggleFavorite, {
        contact_id: contactId
    }, {
        requestKey: `user-favorite-${contactId}`
    });

    const current = state.contacts.find((contact) => contact.id === contactId);
    const isFavorite = utils.toBoolean(
        response?.is_favorite ?? response?.isFavorite,
        !current?.isFavorite
    );

    state.contacts = state.contacts.map((contact) =>
        contact.id === contactId
            ? Object.freeze({ ...contact, isFavorite })
            : contact
    );

    updateFavoriteButton(button, isFavorite);

    if (state.query.favoriteOnly && !isFavorite) {
        await loadContacts({ silent: true });
    } else {
        renderContacts();
    }

    components.toast.success(
        isFavorite ? "เพิ่มในรายการโปรดแล้ว" : "นำออกจากรายการโปรดแล้ว",
        { duration: 2000 }
    );

    return isFavorite;
}

function renderContacts() {
    renderCards();
    renderTable();
    renderPagination();
    setText(SELECTORS.total, utils.formatNumber(state.pagination.total));
    setHidden(SELECTORS.empty, state.contacts.length > 0);
    permissions.apply(document);
}

function renderCards() {
    document.querySelectorAll(SELECTORS.list).forEach((container) => {
        const fragment = document.createDocumentFragment();
        state.contacts.forEach((contact) => {
            fragment.appendChild(createContactCard(contact));
        });
        container.replaceChildren(fragment);
    });
}

function createContactCard(contact) {
    const card = document.createElement("article");
    card.className = "cp-contact-card";

    const header = document.createElement("div");
    header.className = "cp-contact-card__header";

    const identity = document.createElement("div");
    identity.className = "cp-contact-card__identity";
    const avatar = document.createElement("span");
    avatar.className = "cp-glass-avatar";
    avatar.textContent = utils.getInitials(contact.displayName);
    const names = document.createElement("div");
    const name = document.createElement("h3");
    name.textContent = contact.displayName;
    const employee = document.createElement("small");
    employee.textContent = contact.employeeCode || "ไม่มีรหัสพนักงาน";
    names.append(name, employee);
    identity.append(avatar, names);

    const favorite = document.createElement("button");
    favorite.type = "button";
    favorite.className = "cp-favorite-button";
    favorite.dataset.favoriteToggle = String(contact.id);
    updateFavoriteButton(favorite, contact.isFavorite);
    header.append(identity, favorite);

    const details = document.createElement("div");
    details.className = "cp-contact-card__details";
    details.append(
        createDetail("เบอร์ต่อ", contact.extensionNumber || "-"),
        createDetail("แผนก", contact.departmentName || "-"),
        createDetail("สถานที่", contact.locationName || "-")
    );

    const actions = document.createElement("div");
    actions.className = "cp-contact-card__actions";
    actions.appendChild(createDetailButton(contact.id));

    if (contact.extensionNumber) {
        actions.appendChild(
            createCopyButton(contact.extensionNumber, "คัดลอกเบอร์ต่อ")
        );
    }

    card.append(header, details, actions);
    components.initCopyButtons(card);
    return card;
}

function renderTable() {
    document.querySelectorAll(SELECTORS.tableBody).forEach((body) => {
        const fragment = document.createDocumentFragment();

        state.contacts.forEach((contact) => {
            const row = document.createElement("tr");
            row.append(
                createCell(contact.employeeCode || "-"),
                createCell(contact.displayName),
                createCell(contact.extensionNumber || "-"),
                createCell(contact.departmentName || "-"),
                createCell(contact.locationName || "-")
            );

            const actions = document.createElement("td");
            const group = document.createElement("div");
            group.className = "cp-contact-card__actions";
            group.appendChild(createDetailButton(contact.id));

            if (contact.extensionNumber) {
                group.appendChild(
                    createCopyButton(contact.extensionNumber, "คัดลอก")
                );
            }

            const favorite = document.createElement("button");
            favorite.type = "button";
            favorite.className = "cp-favorite-button";
            favorite.dataset.favoriteToggle = String(contact.id);
            updateFavoriteButton(favorite, contact.isFavorite);
            group.appendChild(favorite);
            actions.appendChild(group);
            row.appendChild(actions);
            fragment.appendChild(row);
        });

        body.replaceChildren(fragment);
        components.initCopyButtons(body);
    });
}

function createContactDetail(contact) {
    const container = document.createElement("div");
    container.className = "cp-user-contact-details";

    const profile = document.createElement("section");
    profile.className = "cp-user-contact-profile";
    const avatar = document.createElement("span");
    avatar.className = "cp-user-contact-profile__avatar cp-glass-avatar";
    avatar.textContent = utils.getInitials(contact.displayName);
    const identity = document.createElement("div");
    const name = document.createElement("h3");
    name.textContent = contact.displayName;
    const employee = document.createElement("p");
    employee.textContent = contact.employeeCode || "ไม่มีรหัสพนักงาน";
    identity.append(name, employee);
    profile.append(avatar, identity);

    const fields = document.createElement("section");
    fields.className = "cp-user-contact-details__fields";

    [
        ["เบอร์ต่อ", contact.extensionNumber, true],
        ["เบอร์โทรศัพท์", contact.mobileNumber, true],
        ["อีเมล", contact.email, true],
        ["แผนก", contact.departmentName, false],
        ["สถานที่", contact.locationName, false],
        ["IP Address", contact.ipAddress, true]
    ].forEach(([label, value, copyable]) => {
        fields.appendChild(createDetailRow(label, value || "-", copyable && Boolean(value)));
    });

    container.append(profile, fields);
    return container;
}

function createDetailRow(labelText, value, copyable) {
    const row = document.createElement("div");
    row.className = "cp-user-contact-row";
    const label = document.createElement("strong");
    label.className = "cp-user-contact-row__label";
    label.textContent = labelText;
    const content = document.createElement("span");
    content.textContent = value;
    row.append(label, content);

    if (copyable) {
        const actions = document.createElement("div");
        actions.className = "cp-user-contact-row__actions";
        actions.appendChild(createCopyButton(value, "คัดลอก"));
        row.appendChild(actions);
    }

    return row;
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

function createDetailButton(contactId) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "cp-button cp-button--primary cp-button--small";
    button.textContent = "ดูรายละเอียด";
    button.dataset.contactDetail = String(contactId);
    return button;
}

function createCopyButton(value, label) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "cp-button cp-button--secondary cp-button--small";
    button.textContent = label;
    button.dataset.copyValue = value;
    return button;
}

function createCell(value) {
    const cell = document.createElement("td");
    cell.textContent = String(value);
    return cell;
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

function renderPagination() {
    const { page, totalPages, total, limit } = state.pagination;
    const start = total === 0 ? 0 : ((page - 1) * limit) + 1;
    const end = Math.min(page * limit, total);

    setText(
        SELECTORS.summary,
        `${utils.formatNumber(start)}-${utils.formatNumber(end)} จาก ${utils.formatNumber(total)}`
    );

    document.querySelectorAll(SELECTORS.pagination).forEach((container) => {
        const fragment = document.createDocumentFragment();
        fragment.appendChild(createPageButton("ก่อนหน้า", page - 1, page <= 1));

        buildPageNumbers(page, totalPages).forEach((value) => {
            if (value === "ellipsis") {
                const span = document.createElement("span");
                span.textContent = "…";
                fragment.appendChild(span);
            } else {
                const button = createPageButton(String(value), value, false);
                if (value === page) button.setAttribute("aria-current", "page");
                fragment.appendChild(button);
            }
        });

        fragment.appendChild(
            createPageButton("ถัดไป", page + 1, page >= totalPages)
        );
        container.replaceChildren(fragment);
    });
}

function buildPageNumbers(current, total) {
    if (total <= 7) {
        return Array.from({ length: total }, (_, index) => index + 1);
    }

    const pages = [1];
    if (current > 4) pages.push("ellipsis");

    for (
        let value = Math.max(2, current - 1);
        value <= Math.min(total - 1, current + 1);
        value += 1
    ) {
        pages.push(value);
    }

    if (current < total - 3) pages.push("ellipsis");
    pages.push(total);
    return pages;
}

function createPageButton(label, page, disabled) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "cp-pagination__button";
    button.textContent = label;
    button.dataset.page = String(page);
    button.disabled = disabled;
    return button;
}

function populateSelect(selector, options, placeholder, selectedValue) {
    document.querySelectorAll(selector).forEach((select) => {
        const fragment = document.createDocumentFragment();
        const initial = document.createElement("option");
        initial.value = "";
        initial.textContent = placeholder;
        fragment.appendChild(initial);

        options.forEach((item) => {
            const option = document.createElement("option");
            option.value = String(item.id);
            option.textContent = item.name;
            fragment.appendChild(option);
        });

        select.replaceChildren(fragment);
        select.value = String(selectedValue || "");
    });
}

function restoreQueryFromUrl() {
    const params = utils.getQueryParams();
    state.query.search = String(params.search || "");
    state.query.departmentId = String(params.department_id || "");
    state.query.locationId = String(params.location_id || "");
    state.query.favoriteOnly = utils.toBoolean(params.favorite_only, false);
    state.query.page = Math.max(1, utils.toInteger(params.page, 1));
    state.query.limit = utils.clamp(
        utils.toInteger(params.limit, CONFIG.pageSize),
        10,
        100
    );
}

function syncControls() {
    setControlValue(SELECTORS.search, state.query.search);
    setControlValue(SELECTORS.departmentFilter, state.query.departmentId);
    setControlValue(SELECTORS.locationFilter, state.query.locationId);
    setControlValue(SELECTORS.pageSize, state.query.limit);

    const favorite = document.querySelector(SELECTORS.favoriteFilter);
    if (favorite instanceof HTMLInputElement) {
        favorite.checked = state.query.favoriteOnly;
    }
}

function syncQueryToUrl() {
    utils.updateQueryParams({
        search: state.query.search,
        department_id: state.query.departmentId,
        location_id: state.query.locationId,
        favorite_only: state.query.favoriteOnly ? 1 : null,
        page: state.query.page > 1 ? state.query.page : null,
        limit: state.query.limit !== CONFIG.pageSize ? state.query.limit : null
    }, { replace: true });
}

function resetFilters() {
    state.query = {
        search: "",
        departmentId: "",
        locationId: "",
        favoriteOnly: false,
        sort: "display_name",
        direction: "asc",
        page: 1,
        limit: CONFIG.pageSize
    };
    syncControls();
    const sort = document.querySelector(SELECTORS.sort);
    if (sort) sort.value = "display_name:asc";
    loadContacts().catch(() => {});
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
        departmentName: String(
            value.department_name || value.departmentName || ""
        ),
        locationName: String(value.location_name || value.locationName || ""),
        ipAddress: String(value.ip_address || value.ipAddress || ""),
        isFavorite: utils.toBoolean(
            value.is_favorite ?? value.isFavorite,
            false
        )
    });
}

function normalizeOption(value) {
    const id = value?.id ?? value?.department_id ?? value?.location_id;
    if (id === undefined || id === null || id === "") return null;

    return {
        id,
        name: String(
            value.name ||
            value.display_name ||
            value.department_name ||
            value.location_name ||
            "ไม่ระบุ"
        )
    };
}

function normalizePagination(meta = {}, itemCount = 0) {
    const limit = utils.clamp(
        utils.toInteger(meta.limit, state.query.limit),
        1,
        100
    );
    const total = Math.max(0, utils.toInteger(meta.total, itemCount));
    const totalPages = Math.max(
        1,
        utils.toInteger(
            meta.totalPages ?? meta.total_pages,
            Math.ceil(total / limit) || 1
        )
    );
    const page = utils.clamp(
        utils.toInteger(meta.page, state.query.page),
        1,
        totalPages
    );

    return { page, limit, total, totalPages };
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

function setControlValue(selector, value) {
    const element = document.querySelector(selector);
    if (element) element.value = String(value ?? "");
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
    console.error("[ConnectPro User Contacts]", error);
    components.toast.error(error?.message || fallback);
}

document.addEventListener("DOMContentLoaded", initializeContacts, {
    once: true
});

export default Object.freeze({
    init: initializeContacts,
    load: loadContacts,
    detail: showContactDetail,
    toggleFavorite
});
