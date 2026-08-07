/**
 * ConnectPro User Departments
 * File: frontend/assets/js/user/departments.js
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
        list: "user/departments/list.php",
        detail: "user/departments/detail.php"
    }),
    contactsPage: "/connectpro/frontend/pages/user/contacts.html",
    pageSize: 20,
    searchDelay: 350
});

const SELECTORS = Object.freeze({
    page: "[data-user-departments]",
    search: "[data-department-search]",
    sort: "[data-department-sort]",
    pageSize: "[data-department-page-size]",
    list: "[data-departments-list]",
    tableBody: "[data-departments-table-body]",
    empty: "[data-departments-empty]",
    loading: "[data-departments-loading]",
    error: "[data-departments-error]",
    total: "[data-departments-total]",
    pageSummary: "[data-departments-page-summary]",
    pagination: "[data-departments-pagination]",
    resetButton: "[data-departments-reset]",
    refreshButton: "[data-departments-refresh]",
    logoutButton: "[data-logout]"
});

const state = {
    initialized: false,
    loading: false,
    departments: [],
    query: {
        search: "",
        sort: "sort_order",
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

async function initializeDepartments() {
    if (state.initialized) return;
    state.initialized = true;

    components.init();
    auth.init();

    try {
        const user = await auth.requireAuth({ roles: ["admin", "user"] });
        if (!user) return;

        permissions.init();
        permissions.authorize(PERMISSIONS.DEPARTMENTS.VIEW);
        auth.hydrateUserElements(document, user);
        restoreQueryFromUrl();
        syncControls();
        bindEvents();

        await Promise.allSettled([
            notifications.init({ showToastForNew: true }),
            loadDepartments()
        ]);
    } catch (error) {
        handleError(error, "ไม่สามารถเริ่มต้นหน้ารายการแผนกได้");
    }
}

export async function loadDepartments(options = {}) {
    if (state.loading) return;

    state.loading = true;
    setLoading(true);
    hideError();

    try {
        const response = await api.get(CONFIG.endpoints.list, {
            query: {
                search: state.query.search,
                status: "active",
                sort: state.query.sort,
                direction: state.query.direction,
                page: state.query.page,
                limit: state.query.limit
            },
            requestKey: "user-departments-list",
            cancelPrevious: true,
            returnMeta: true
        });

        const payload = response.data || {};
        const items = Array.isArray(payload)
            ? payload
            : payload.departments || payload.items || [];

        state.departments = items.map(normalizeDepartment).filter(Boolean);
        state.pagination = normalizePagination(
            response.meta || payload.meta || payload.pagination,
            state.departments.length
        );
        state.query.page = state.pagination.page;

        renderDepartments();
        syncQueryToUrl();

        if (options.showSuccess) {
            components.toast.success("อัปเดตรายการแผนกแล้ว", {
                duration: 2000
            });
        }
    } catch (error) {
        if (!(error instanceof ApiError && error.isCancelled)) {
            showError(error.message || "โหลดรายการแผนกไม่สำเร็จ");
            if (!options.silent) {
                handleError(error, "โหลดรายการแผนกไม่สำเร็จ");
            }
        }
    } finally {
        state.loading = false;
        setLoading(false);
    }
}

function bindEvents() {
    const searchHandler = utils.debounce((value) => {
        state.query.search = utils.normalizeText(value);
        state.query.page = 1;
        loadDepartments().catch(() => {});
    }, CONFIG.searchDelay);

    document.querySelector(SELECTORS.search)?.addEventListener("input", (event) => {
        searchHandler(event.target.value);
    });

    document.querySelector(SELECTORS.sort)?.addEventListener("change", (event) => {
        const [sort, direction = "asc"] = event.target.value.split(":");
        state.query.sort = sort;
        state.query.direction = direction;
        state.query.page = 1;
        loadDepartments().catch(() => {});
    });

    document.querySelector(SELECTORS.pageSize)?.addEventListener("change", (event) => {
        state.query.limit = utils.clamp(
            utils.toInteger(event.target.value, CONFIG.pageSize),
            10,
            100
        );
        state.query.page = 1;
        loadDepartments().catch(() => {});
    });

    document.querySelectorAll(SELECTORS.resetButton).forEach((button) => {
        button.addEventListener("click", resetFilters);
    });

    document.querySelectorAll(SELECTORS.refreshButton).forEach((button) => {
        button.addEventListener("click", () => {
            components.withButtonLoading(
                button,
                () => loadDepartments({ showSuccess: true }),
                { text: "กำลังอัปเดต..." }
            ).catch(() => {});
        });
    });

    document.querySelectorAll(SELECTORS.logoutButton).forEach((button) => {
        button.addEventListener("click", handleLogout);
    });

    document.addEventListener("click", handleDelegatedClick);
}

async function handleDelegatedClick(event) {
    const openButton = event.target.closest("[data-department-open]");
    const detailButton = event.target.closest("[data-department-detail]");
    const pageButton = event.target.closest("[data-page]");

    try {
        if (openButton) {
            openDepartmentContacts(
                utils.toInteger(openButton.dataset.departmentOpen)
            );
        } else if (detailButton) {
            await showDepartmentDetail(
                utils.toInteger(detailButton.dataset.departmentDetail)
            );
        } else if (pageButton && !pageButton.disabled) {
            state.query.page = utils.toInteger(pageButton.dataset.page, 1);
            await loadDepartments();
        }
    } catch (error) {
        handleError(error, "ดำเนินการไม่สำเร็จ");
    }
}

export function openDepartmentContacts(departmentId) {
    assertValidId(departmentId);
    const query = utils.buildQueryString({ department_id: departmentId });
    window.location.assign(`${CONFIG.contactsPage}${query}`);
}

export async function showDepartmentDetail(departmentId) {
    assertValidId(departmentId);

    const response = await api.get(CONFIG.endpoints.detail, {
        query: { id: departmentId },
        requestKey: `user-department-detail-${departmentId}`
    });
    const department = normalizeDepartment(response?.department || response);

    if (!department) {
        throw new Error("ไม่พบข้อมูลแผนก");
    }

    const content = createDepartmentDetail(department);
    const modal = components.openModal({
        title: "รายละเอียดแผนก",
        content,
        actions: [
            {
                label: "ปิด",
                className: "cp-button cp-button--secondary",
                onClick: () => modal.close("close")
            },
            {
                label: "ดูรายชื่อผู้ติดต่อ",
                className: "cp-button cp-button--primary",
                onClick: () => openDepartmentContacts(department.id)
            }
        ]
    });
}

function renderDepartments() {
    renderCards();
    renderTable();
    renderPagination();
    setText(SELECTORS.total, utils.formatNumber(state.pagination.total));
    setHidden(SELECTORS.empty, state.departments.length > 0);
    permissions.apply(document);
}

function renderCards() {
    document.querySelectorAll(SELECTORS.list).forEach((container) => {
        const fragment = document.createDocumentFragment();

        state.departments.forEach((department) => {
            fragment.appendChild(createDepartmentCard(department));
        });

        container.replaceChildren(fragment);
    });
}

function createDepartmentCard(department) {
    const card = document.createElement("article");
    card.className = "cp-user-department-card";

    const header = document.createElement("div");
    header.className = "cp-user-department-card__header";

    const nameGroup = document.createElement("div");
    const name = document.createElement("h3");
    name.textContent = department.name;
    const code = document.createElement("small");
    code.textContent = department.code || "ไม่มีรหัสแผนก";
    nameGroup.append(name, code);

    const count = document.createElement("strong");
    count.className = "cp-user-department-card__count";
    count.textContent = utils.formatNumber(department.contactCount);
    count.setAttribute(
        "aria-label",
        `ผู้ติดต่อ ${department.contactCount} รายชื่อ`
    );
    header.append(nameGroup, count);

    const description = document.createElement("p");
    description.textContent = department.description || "ไม่มีรายละเอียดแผนก";

    const actions = document.createElement("div");
    actions.className = "cp-contact-card__actions";
    actions.append(
        createActionButton(
            "รายละเอียด",
            "departmentDetail",
            department.id,
            "secondary"
        ),
        createActionButton(
            "ดูรายชื่อ",
            "departmentOpen",
            department.id,
            "primary"
        )
    );

    card.append(header, description, actions);
    return card;
}

function renderTable() {
    document.querySelectorAll(SELECTORS.tableBody).forEach((body) => {
        const fragment = document.createDocumentFragment();

        state.departments.forEach((department) => {
            const row = document.createElement("tr");
            row.append(
                createCell(department.code || "-"),
                createCell(department.name),
                createCell(department.description || "-"),
                createCell(utils.formatNumber(department.contactCount))
            );

            const actionsCell = document.createElement("td");
            const actions = document.createElement("div");
            actions.className = "cp-contact-card__actions";
            actions.append(
                createActionButton(
                    "รายละเอียด",
                    "departmentDetail",
                    department.id,
                    "secondary"
                ),
                createActionButton(
                    "ดูรายชื่อ",
                    "departmentOpen",
                    department.id,
                    "primary"
                )
            );
            actionsCell.appendChild(actions);
            row.appendChild(actionsCell);
            fragment.appendChild(row);
        });

        body.replaceChildren(fragment);
    });
}

function createDepartmentDetail(department) {
    const container = document.createElement("div");
    container.className = "cp-user-contact-details";

    const summary = document.createElement("section");
    summary.className = "cp-user-department-summary";
    const name = document.createElement("h3");
    name.textContent = department.name;
    const code = document.createElement("p");
    code.textContent = `รหัสแผนก: ${department.code || "-"}`;
    const count = document.createElement("strong");
    count.textContent = `${utils.formatNumber(department.contactCount)} รายชื่อ`;
    summary.append(name, code, count);

    const details = document.createElement("section");
    details.className = "cp-user-contact-details__fields";
    details.append(
        createDetailRow("รายละเอียด", department.description || "-"),
        createDetailRow("สถานที่หลัก", department.locationName || "-"),
        createDetailRow("ผู้ติดต่อทั้งหมด", utils.formatNumber(department.contactCount))
    );

    container.append(summary, details);
    return container;
}

function createDetailRow(labelText, value) {
    const row = document.createElement("div");
    row.className = "cp-user-contact-row";
    const label = document.createElement("strong");
    label.className = "cp-user-contact-row__label";
    label.textContent = labelText;
    const content = document.createElement("span");
    content.textContent = String(value);
    row.append(label, content);
    return row;
}

function createActionButton(label, datasetKey, id, variant) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = `cp-button cp-button--${variant} cp-button--small`;
    button.textContent = label;
    button.dataset[datasetKey] = String(id);
    return button;
}

function createCell(value) {
    const cell = document.createElement("td");
    cell.textContent = String(value);
    return cell;
}

function renderPagination() {
    const { page, totalPages, total, limit } = state.pagination;
    const start = total === 0 ? 0 : ((page - 1) * limit) + 1;
    const end = Math.min(page * limit, total);

    setText(
        SELECTORS.pageSummary,
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
                return;
            }

            const button = createPageButton(String(value), value, false);
            if (value === page) button.setAttribute("aria-current", "page");
            fragment.appendChild(button);
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

function restoreQueryFromUrl() {
    const params = utils.getQueryParams();
    state.query.search = String(params.search || "");
    state.query.page = Math.max(1, utils.toInteger(params.page, 1));
    state.query.limit = utils.clamp(
        utils.toInteger(params.limit, CONFIG.pageSize),
        10,
        100
    );
}

function syncControls() {
    setControlValue(SELECTORS.search, state.query.search);
    setControlValue(SELECTORS.pageSize, state.query.limit);
    setControlValue(
        SELECTORS.sort,
        `${state.query.sort}:${state.query.direction}`
    );
}

function syncQueryToUrl() {
    utils.updateQueryParams({
        search: state.query.search,
        page: state.query.page > 1 ? state.query.page : null,
        limit: state.query.limit !== CONFIG.pageSize ? state.query.limit : null
    }, { replace: true });
}

function resetFilters() {
    state.query = {
        search: "",
        sort: "sort_order",
        direction: "asc",
        page: 1,
        limit: CONFIG.pageSize
    };
    syncControls();
    loadDepartments().catch(() => {});
}

function normalizeDepartment(value) {
    const id = Number(value?.id || value?.department_id || 0);
    if (!Number.isInteger(id) || id < 1) return null;

    return Object.freeze({
        id,
        code: String(value.code || value.department_code || ""),
        name: String(value.name || value.department_name || "ไม่ระบุแผนก"),
        description: String(value.description || ""),
        locationName: String(
            value.location_name || value.locationName || ""
        ),
        contactCount: Math.max(
            0,
            utils.toInteger(value.contact_count ?? value.contactCount, 0)
        ),
        sortOrder: Math.max(
            0,
            utils.toInteger(value.sort_order ?? value.sortOrder, 0)
        )
    });
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

function assertValidId(departmentId) {
    if (!Number.isInteger(departmentId) || departmentId < 1) {
        throw new TypeError("Department ID ไม่ถูกต้อง");
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
    console.error("[ConnectPro User Departments]", error);
    components.toast.error(error?.message || fallback);
}

document.addEventListener("DOMContentLoaded", initializeDepartments, {
    once: true
});

export default Object.freeze({
    init: initializeDepartments,
    load: loadDepartments,
    detail: showDepartmentDetail,
    openContacts: openDepartmentContacts
});
