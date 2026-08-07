/**
 * ConnectPro Admin Departments
 * File: frontend/assets/js/admin/departments.js
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
        list: "admin/departments/list.php",
        detail: "admin/departments/detail.php",
        create: "admin/departments/create.php",
        update: "admin/departments/update.php",
        remove: "admin/departments/delete.php",
        reorder: "admin/departments/reorder.php"
    }),
    pageSize: 20,
    searchDelay: 350
});

const SELECTORS = Object.freeze({
    page: "[data-admin-departments]",
    tableBody: "[data-departments-table-body]",
    cardList: "[data-departments-card-list]",
    empty: "[data-departments-empty]",
    loading: "[data-departments-loading]",
    error: "[data-departments-error]",
    search: "[data-department-search]",
    statusFilter: "[data-department-status-filter]",
    pageSize: "[data-department-page-size]",
    total: "[data-departments-total]",
    pageSummary: "[data-departments-page-summary]",
    pagination: "[data-departments-pagination]",
    addButton: "[data-department-add]",
    refreshButton: "[data-departments-refresh]",
    resetButton: "[data-departments-reset]",
    formTemplate: "#departmentFormTemplate",
    form: "[data-department-form]",
    logoutButton: "[data-logout]"
});

const state = {
    initialized: false,
    loading: false,
    departments: [],
    query: {
        search: "",
        status: "",
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
        const user = await auth.requireAuth({ roles: ["admin"] });
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
        handleError(error, "ไม่สามารถเริ่มต้นหน้าจัดการแผนกได้");
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
                status: state.query.status,
                page: state.query.page,
                limit: state.query.limit,
                sort: "sort_order",
                direction: "asc"
            },
            requestKey: "admin-departments-list",
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
            components.toast.success("อัปเดตรายการแผนกแล้ว", { duration: 2000 });
        }
    } catch (error) {
        if (!(error instanceof ApiError && error.isCancelled)) {
            showError(error.message || "โหลดข้อมูลแผนกไม่สำเร็จ");
            if (!options.silent) handleError(error, "โหลดข้อมูลแผนกไม่สำเร็จ");
        }
    } finally {
        state.loading = false;
        setLoading(false);
    }
}

function bindEvents() {
    const search = document.querySelector(SELECTORS.search);
    const debouncedSearch = utils.debounce((value) => {
        state.query.search = utils.normalizeText(value);
        state.query.page = 1;
        loadDepartments().catch(() => {});
    }, CONFIG.searchDelay);

    search?.addEventListener("input", (event) => debouncedSearch(event.target.value));

    document.querySelector(SELECTORS.statusFilter)?.addEventListener("change", (event) => {
        state.query.status = event.target.value;
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

    document.querySelectorAll(SELECTORS.addButton).forEach((button) => {
        button.addEventListener("click", () => openDepartmentForm());
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

    document.querySelectorAll(SELECTORS.resetButton).forEach((button) => {
        button.addEventListener("click", resetFilters);
    });

    document.querySelectorAll(SELECTORS.logoutButton).forEach((button) => {
        button.addEventListener("click", handleLogout);
    });

    document.addEventListener("click", handleDelegatedClick);
}

async function handleDelegatedClick(event) {
    const editButton = event.target.closest("[data-department-edit]");
    const deleteButton = event.target.closest("[data-department-delete]");
    const moveButton = event.target.closest("[data-department-move]");
    const pageButton = event.target.closest("[data-page]");

    try {
        if (editButton) {
            await openDepartmentForm(utils.toInteger(editButton.dataset.departmentEdit));
        } else if (deleteButton) {
            await deleteDepartment(utils.toInteger(deleteButton.dataset.departmentDelete));
        } else if (moveButton) {
            await moveDepartment(
                utils.toInteger(moveButton.dataset.departmentId),
                moveButton.dataset.departmentMove
            );
        } else if (pageButton && !pageButton.disabled) {
            state.query.page = utils.toInteger(pageButton.dataset.page, 1);
            await loadDepartments();
        }
    } catch (error) {
        handleError(error, "ดำเนินการไม่สำเร็จ");
    }
}

async function openDepartmentForm(departmentId = null) {
    const editing = Number.isInteger(departmentId) && departmentId > 0;
    permissions.authorize(
        editing ? PERMISSIONS.DEPARTMENTS.UPDATE : PERMISSIONS.DEPARTMENTS.CREATE
    );

    let department = null;

    if (editing) {
        const response = await api.get(CONFIG.endpoints.detail, {
            query: { id: departmentId },
            requestKey: `department-detail-${departmentId}`
        });
        department = normalizeDepartment(response?.department || response);
    }

    const content = buildDepartmentForm(department);
    const modal = components.openModal({
        title: editing ? "แก้ไขแผนก" : "เพิ่มแผนก",
        content,
        closeOnBackdrop: false,
        actions: [
            {
                label: "ยกเลิก",
                className: "cp-button cp-button--secondary",
                onClick: () => modal.close("cancel")
            },
            {
                label: editing ? "บันทึกการแก้ไข" : "เพิ่มแผนก",
                className: "cp-button cp-button--primary",
                loadingText: "กำลังบันทึก...",
                autofocus: true,
                onClick: async () => {
                    const saved = await saveDepartment(content, departmentId);
                    if (saved) modal.close("saved");
                }
            }
        ]
    });
}

function buildDepartmentForm(department) {
    const template = document.querySelector(SELECTORS.formTemplate);
    let wrapper;

    if (template instanceof HTMLTemplateElement) {
        wrapper = document.createElement("div");
        wrapper.appendChild(template.content.cloneNode(true));
    } else {
        wrapper = createFallbackForm();
    }

    const form = wrapper.querySelector(SELECTORS.form) || wrapper.querySelector("form");
    if (!form) throw new Error("ไม่พบ Department Form");

    if (department) {
        utils.setFormValues(form, {
            code: department.code,
            name: department.name,
            description: department.description,
            sort_order: department.sortOrder,
            status: department.status
        });
    }

    return wrapper;
}

function createFallbackForm() {
    const wrapper = document.createElement("div");
    const form = document.createElement("form");
    form.dataset.departmentForm = "";
    form.className = "cp-admin-form-grid";

    form.append(
        createInputGroup("code", "รหัสแผนก", "text", true),
        createInputGroup("name", "ชื่อแผนก", "text", true),
        createInputGroup("sort_order", "ลำดับแสดงผล", "number", true),
        createSelectGroup("status", "สถานะ", [
            { value: "active", label: "Active" },
            { value: "inactive", label: "Inactive" }
        ])
    );

    const descriptionGroup = document.createElement("label");
    descriptionGroup.className = "cp-form-group cp-admin-form-grid__full";
    const label = document.createElement("span");
    label.textContent = "รายละเอียด";
    const textarea = document.createElement("textarea");
    textarea.name = "description";
    textarea.className = "cp-textarea";
    textarea.rows = 4;
    descriptionGroup.append(label, textarea);
    form.appendChild(descriptionGroup);

    wrapper.appendChild(form);
    return wrapper;
}

function createInputGroup(name, labelText, type, required) {
    const group = document.createElement("label");
    group.className = "cp-form-group";
    const label = document.createElement("span");
    label.textContent = labelText;
    const input = document.createElement("input");
    input.name = name;
    input.type = type;
    input.className = "cp-input";
    input.required = required;

    if (name === "sort_order") {
        input.min = "0";
        input.value = String(nextSortOrder());
    }

    const error = document.createElement("small");
    error.dataset.fieldError = name;
    error.hidden = true;
    group.append(label, input, error);
    return group;
}

function createSelectGroup(name, labelText, options) {
    const group = document.createElement("label");
    group.className = "cp-form-group";
    const label = document.createElement("span");
    label.textContent = labelText;
    const select = document.createElement("select");
    select.name = name;
    select.className = "cp-select";

    options.forEach((item) => {
        const option = document.createElement("option");
        option.value = item.value;
        option.textContent = item.label;
        select.appendChild(option);
    });

    group.append(label, select);
    return group;
}

async function saveDepartment(wrapper, departmentId) {
    const form = wrapper.querySelector(SELECTORS.form) || wrapper.querySelector("form");
    utils.clearFormErrors(form);

    if (!form.reportValidity()) return false;

    const values = utils.serializeForm(form);
    const errors = validateDepartment(values);

    if (Object.keys(errors).length > 0) {
        utils.applyFormErrors(form, errors);
        form.querySelector("[aria-invalid=\"true\"]")?.focus();
        return false;
    }

    const payload = {
        code: utils.normalizeText(values.code).toUpperCase(),
        name: utils.normalizeText(values.name),
        description: utils.normalizeText(values.description),
        sort_order: Math.max(0, utils.toInteger(values.sort_order, 0)),
        status: values.status || "active"
    };

    try {
        if (departmentId) {
            await api.put(CONFIG.endpoints.update, {
                ...payload,
                id: departmentId
            }, {
                requestKey: `department-update-${departmentId}`
            });
        } else {
            await api.post(CONFIG.endpoints.create, payload, {
                requestKey: "department-create"
            });
        }

        components.toast.success(
            departmentId ? "แก้ไขแผนกแล้ว" : "เพิ่มแผนกแล้ว"
        );
        await loadDepartments({ silent: true });
        return true;
    } catch (error) {
        if (error.status === 422 && utils.isPlainObject(error.details)) {
            utils.applyFormErrors(form, error.details);
            return false;
        }

        handleError(error, "บันทึกข้อมูลแผนกไม่สำเร็จ");
        return false;
    }
}

function validateDepartment(values) {
    const errors = {};
    const code = utils.normalizeText(values.code);
    const name = utils.normalizeText(values.name);

    if (!code) {
        errors.code = ["กรุณากรอกรหัสแผนก"];
    } else if (!/^[A-Za-z0-9_-]{2,20}$/.test(code)) {
        errors.code = ["ใช้ตัวอักษร ตัวเลข _ หรือ - จำนวน 2-20 ตัว"];
    }

    if (!name) {
        errors.name = ["กรุณากรอกชื่อแผนก"];
    } else if (!utils.validateLength(name, { min: 2, max: 100 })) {
        errors.name = ["ชื่อแผนกต้องมี 2-100 ตัวอักษร"];
    }

    if (utils.toInteger(values.sort_order, -1) < 0) {
        errors.sort_order = ["ลำดับต้องเป็น 0 หรือมากกว่า"];
    }

    return errors;
}

async function deleteDepartment(departmentId) {
    permissions.authorize(PERMISSIONS.DEPARTMENTS.DELETE);
    const department = state.departments.find((item) => item.id === departmentId);

    const confirmed = await components.confirm({
        title: "ยืนยันการลบแผนก",
        message: `ต้องการลบ ${department?.name || "แผนกนี้"} หรือไม่`,
        confirmText: "ลบแผนก",
        cancelText: "ยกเลิก",
        variant: "danger"
    });

    if (!confirmed) return;

    try {
        await api.delete(CONFIG.endpoints.remove, {
            query: { id: departmentId },
            requestKey: `department-delete-${departmentId}`
        });
        components.toast.success("ลบแผนกแล้ว");
        await loadDepartments({ silent: true });
    } catch (error) {
        handleError(error, "ลบแผนกไม่สำเร็จ");
    }
}

async function moveDepartment(departmentId, direction) {
    permissions.authorize(PERMISSIONS.DEPARTMENTS.UPDATE);

    if (!["up", "down"].includes(direction)) {
        throw new TypeError("Department move direction is invalid.");
    }

    await api.patch(CONFIG.endpoints.reorder, {
        id: departmentId,
        direction
    }, {
        requestKey: `department-move-${departmentId}`
    });

    await loadDepartments({ silent: true });
}

function renderDepartments() {
    renderTable();
    renderCards();
    renderPagination();
    setText(SELECTORS.total, utils.formatNumber(state.pagination.total));
    toggleEmpty(state.departments.length === 0);
    permissions.apply(document);
}

function renderTable() {
    document.querySelectorAll(SELECTORS.tableBody).forEach((body) => {
        const fragment = document.createDocumentFragment();
        state.departments.forEach((department, index) => {
            fragment.appendChild(createTableRow(department, index));
        });
        body.replaceChildren(fragment);
    });
}

function createTableRow(department, index) {
    const row = document.createElement("tr");
    row.dataset.departmentId = String(department.id);

    [
        department.sortOrder,
        department.code,
        department.name,
        department.contactCount
    ].forEach((value) => {
        const cell = document.createElement("td");
        cell.textContent = String(value ?? "-");
        row.appendChild(cell);
    });

    const statusCell = document.createElement("td");
    statusCell.appendChild(createStatusBadge(department.status));

    const actionCell = document.createElement("td");
    actionCell.appendChild(createActions(department, index));
    row.append(statusCell, actionCell);
    return row;
}

function renderCards() {
    document.querySelectorAll(SELECTORS.cardList).forEach((container) => {
        const fragment = document.createDocumentFragment();

        state.departments.forEach((department, index) => {
            const card = document.createElement("article");
            card.className = "cp-user-department-card";

            const header = document.createElement("div");
            header.className = "cp-user-department-card__header";
            const name = document.createElement("h3");
            name.textContent = department.name;
            header.append(name, createStatusBadge(department.status));

            const code = document.createElement("p");
            code.textContent = `รหัส: ${department.code}`;
            const count = document.createElement("strong");
            count.textContent = `${utils.formatNumber(department.contactCount)} รายชื่อ`;

            card.append(header, code, count, createActions(department, index));
            fragment.appendChild(card);
        });

        container.replaceChildren(fragment);
    });
}

function createActions(department, index) {
    const actions = document.createElement("div");
    actions.className = "cp-contact-card__actions";

    actions.append(
        createMoveButton(department.id, "up", "ขึ้น", index === 0),
        createMoveButton(
            department.id,
            "down",
            "ลง",
            index === state.departments.length - 1
        ),
        createActionButton(
            "แก้ไข",
            "departmentEdit",
            department.id,
            PERMISSIONS.DEPARTMENTS.UPDATE
        ),
        createActionButton(
            "ลบ",
            "departmentDelete",
            department.id,
            PERMISSIONS.DEPARTMENTS.DELETE,
            true,
            department.contactCount > 0
        )
    );

    return actions;
}

function createMoveButton(id, direction, label, disabled) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "cp-icon-button";
    button.textContent = label;
    button.dataset.departmentId = String(id);
    button.dataset.departmentMove = direction;
    button.dataset.permission = PERMISSIONS.DEPARTMENTS.UPDATE;
    button.disabled = disabled;
    return button;
}

function createActionButton(
    label,
    datasetKey,
    id,
    permission,
    danger = false,
    disabled = false
) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = danger
        ? "cp-button cp-button--danger cp-button--small"
        : "cp-button cp-button--secondary cp-button--small";
    button.textContent = label;
    button.dataset[datasetKey] = String(id);
    button.dataset.permission = permission;
    button.dataset.permissionDenied = "hide";
    button.disabled = disabled;

    if (disabled) {
        button.title = "ไม่สามารถลบแผนกที่มีผู้ติดต่ออยู่ได้";
    }

    return button;
}

function createStatusBadge(status) {
    const badge = document.createElement("span");
    badge.className = `cp-badge cp-badge--${status === "active" ? "success" : "neutral"}`;
    badge.textContent = status === "active" ? "Active" : "Inactive";
    return badge;
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
    state.query.status = String(params.status || "");
    state.query.page = Math.max(1, utils.toInteger(params.page, 1));
    state.query.limit = utils.clamp(
        utils.toInteger(params.limit, CONFIG.pageSize),
        10,
        100
    );
}

function syncControls() {
    const search = document.querySelector(SELECTORS.search);
    const status = document.querySelector(SELECTORS.statusFilter);
    const pageSize = document.querySelector(SELECTORS.pageSize);

    if (search) search.value = state.query.search;
    if (status) status.value = state.query.status;
    if (pageSize) pageSize.value = String(state.query.limit);
}

function syncQueryToUrl() {
    utils.updateQueryParams({
        search: state.query.search,
        status: state.query.status,
        page: state.query.page > 1 ? state.query.page : null,
        limit: state.query.limit !== CONFIG.pageSize ? state.query.limit : null
    }, { replace: true });
}

function resetFilters() {
    state.query = {
        search: "",
        status: "",
        page: 1,
        limit: CONFIG.pageSize
    };
    syncControls();
    loadDepartments().catch(() => {});
}

function normalizeDepartment(value) {
    const id = Number(value?.id || 0);
    if (!Number.isInteger(id) || id < 1) return null;

    return Object.freeze({
        id,
        code: String(value.code || value.department_code || ""),
        name: String(value.name || value.department_name || "ไม่ระบุชื่อ"),
        description: String(value.description || ""),
        sortOrder: Math.max(
            0,
            utils.toInteger(value.sort_order ?? value.sortOrder, 0)
        ),
        contactCount: Math.max(
            0,
            utils.toInteger(value.contact_count ?? value.contactCount, 0)
        ),
        status: String(value.status || "active").toLowerCase()
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

function nextSortOrder() {
    return Math.max(0, ...state.departments.map((item) => item.sortOrder)) + 1;
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

function toggleEmpty(visible) {
    document.querySelectorAll(SELECTORS.empty).forEach((element) => {
        element.hidden = !visible;
    });
}

function showError(message) {
    document.querySelectorAll(SELECTORS.error).forEach((element) => {
        element.textContent = message;
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
    console.error("[ConnectPro Admin Departments]", error);
    components.toast.error(error?.message || fallback);
}

document.addEventListener("DOMContentLoaded", initializeDepartments, {
    once: true
});

export default Object.freeze({
    init: initializeDepartments,
    load: loadDepartments,
    openForm: openDepartmentForm,
    remove: deleteDepartment,
    move: moveDepartment
});
