/**
 * ConnectPro Admin Contacts
 * File: frontend/assets/js/admin/contacts.js
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
        list: "admin/contacts/list.php",
        detail: "admin/contacts/detail.php",
        create: "admin/contacts/create.php",
        update: "admin/contacts/update.php",
        remove: "admin/contacts/delete.php",
        restore: "admin/contacts/restore.php",
        export: "admin/contacts/export.php",
        departments: "departments/list.php",
        locations: "locations/list.php"
    }),
    pageSize: 20,
    searchDelay: 350,
    exportFileName: "connectpro-contacts.xlsx"
});

const SELECTORS = Object.freeze({
    page: "[data-admin-contacts]",
    tableBody: "[data-contacts-table-body]",
    cardList: "[data-contacts-card-list]",
    empty: "[data-contacts-empty]",
    loading: "[data-contacts-loading]",
    error: "[data-contacts-error]",
    search: "[data-contact-search]",
    departmentFilter: "[data-contact-department-filter]",
    locationFilter: "[data-contact-location-filter]",
    statusFilter: "[data-contact-status-filter]",
    sort: "[data-contact-sort]",
    pageSize: "[data-contact-page-size]",
    total: "[data-contacts-total]",
    pageSummary: "[data-contacts-page-summary]",
    pagination: "[data-contacts-pagination]",
    addButton: "[data-contact-add]",
    exportButton: "[data-contact-export]",
    refreshButton: "[data-contacts-refresh]",
    resetButton: "[data-contacts-reset]",
    formTemplate: "#contactFormTemplate",
    form: "[data-contact-form]",
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
        status: "",
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
        const user = await auth.requireAuth({ roles: ["admin"] });
        if (!user) return;

        permissions.init();
        permissions.authorize(PERMISSIONS.CONTACTS.VIEW);
        auth.hydrateUserElements(document, user);
        restoreQueryFromUrl();
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
                status: state.query.status,
                sort: state.query.sort,
                direction: state.query.direction,
                page: state.query.page,
                limit: state.query.limit
            },
            requestKey: "admin-contacts-list",
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
    const [departmentResult, locationResult] = await Promise.allSettled([
        api.get(CONFIG.endpoints.departments, {
            query: { status: "active", limit: 500 },
            requestKey: "contact-departments"
        }),
        api.get(CONFIG.endpoints.locations, {
            query: { status: "active", limit: 500 },
            requestKey: "contact-locations"
        })
    ]);

    if (departmentResult.status === "fulfilled") {
        state.departments = extractItems(departmentResult.value, "departments")
            .map(normalizeOption)
            .filter(Boolean);
    }

    if (locationResult.status === "fulfilled") {
        state.locations = extractItems(locationResult.value, "locations")
            .map(normalizeOption)
            .filter(Boolean);
    }

    populateSelects();
}

function bindEvents() {
    const search = document.querySelector(SELECTORS.search);
    const debouncedSearch = utils.debounce((value) => {
        state.query.search = utils.normalizeText(value);
        state.query.page = 1;
        loadContacts().catch(() => {});
    }, CONFIG.searchDelay);

    search?.addEventListener("input", (event) => debouncedSearch(event.target.value));

    bindFilter(SELECTORS.departmentFilter, "departmentId");
    bindFilter(SELECTORS.locationFilter, "locationId");
    bindFilter(SELECTORS.statusFilter, "status");

    document.querySelector(SELECTORS.sort)?.addEventListener("change", (event) => {
        const [sort, direction = "asc"] = event.target.value.split(":");
        state.query.sort = sort;
        state.query.direction = direction;
        state.query.page = 1;
        loadContacts().catch(() => {});
    });

    document.querySelector(SELECTORS.pageSize)?.addEventListener("change", (event) => {
        state.query.limit = utils.clamp(utils.toInteger(event.target.value, CONFIG.pageSize), 10, 100);
        state.query.page = 1;
        loadContacts().catch(() => {});
    });

    document.querySelectorAll(SELECTORS.addButton).forEach((button) => {
        button.addEventListener("click", () => openContactForm());
    });

    document.querySelectorAll(SELECTORS.exportButton).forEach((button) => {
        button.addEventListener("click", () => exportContacts(button));
    });

    document.querySelectorAll(SELECTORS.refreshButton).forEach((button) => {
        button.addEventListener("click", () => {
            components.withButtonLoading(button, () => loadContacts({ showSuccess: true }), {
                text: "กำลังอัปเดต..."
            }).catch(() => {});
        });
    });

    document.querySelectorAll(SELECTORS.resetButton).forEach((button) => {
        button.addEventListener("click", resetFilters);
    });

    document.addEventListener("click", handleDelegatedClick);

    document.querySelectorAll(SELECTORS.logoutButton).forEach((button) => {
        button.addEventListener("click", handleLogout);
    });
}

function bindFilter(selector, stateKey) {
    document.querySelector(selector)?.addEventListener("change", (event) => {
        state.query[stateKey] = event.target.value;
        state.query.page = 1;
        loadContacts().catch(() => {});
    });
}

async function handleDelegatedClick(event) {
    const editButton = event.target.closest("[data-contact-edit]");
    const deleteButton = event.target.closest("[data-contact-delete]");
    const restoreButton = event.target.closest("[data-contact-restore]");
    const pageButton = event.target.closest("[data-page]");

    if (editButton) {
        await openContactForm(utils.toInteger(editButton.dataset.contactEdit));
    } else if (deleteButton) {
        await deleteContact(utils.toInteger(deleteButton.dataset.contactDelete));
    } else if (restoreButton) {
        await restoreContact(utils.toInteger(restoreButton.dataset.contactRestore));
    } else if (pageButton && !pageButton.disabled) {
        state.query.page = utils.toInteger(pageButton.dataset.page, 1);
        await loadContacts();
    }
}

async function openContactForm(contactId = null) {
    const editing = Number.isInteger(contactId) && contactId > 0;
    permissions.authorize(
        editing ? PERMISSIONS.CONTACTS.UPDATE : PERMISSIONS.CONTACTS.CREATE
    );

    let contact = null;
    if (editing) {
        const response = await api.get(CONFIG.endpoints.detail, {
            query: { id: contactId },
            requestKey: `contact-detail-${contactId}`
        });
        contact = normalizeContact(response?.contact || response);
    }

    const content = buildContactForm(contact);
    const handle = components.openModal({
        title: editing ? "แก้ไขผู้ติดต่อ" : "เพิ่มผู้ติดต่อ",
        content,
        className: "cp-glass-modal--large",
        closeOnBackdrop: false,
        actions: [
            {
                label: "ยกเลิก",
                className: "cp-button cp-button--secondary",
                onClick: () => handle.close("cancel")
            },
            {
                label: editing ? "บันทึกการแก้ไข" : "เพิ่มผู้ติดต่อ",
                className: "cp-button cp-button--primary",
                loadingText: "กำลังบันทึก...",
                autofocus: true,
                onClick: async () => {
                    const saved = await saveContact(content, contactId);
                    if (saved) handle.close("saved");
                }
            }
        ]
    });
}

function buildContactForm(contact) {
    const template = document.querySelector(SELECTORS.formTemplate);
    let wrapper;

    if (template instanceof HTMLTemplateElement) {
        wrapper = document.createElement("div");
        wrapper.appendChild(template.content.cloneNode(true));
    } else {
        wrapper = createFallbackForm();
    }

    const form = wrapper.querySelector(SELECTORS.form) || wrapper.querySelector("form");
    if (!form) throw new Error("ไม่พบ Contact Form");

    populateFormSelect(form, "department_id", state.departments);
    populateFormSelect(form, "location_id", state.locations);

    if (contact) {
        utils.setFormValues(form, {
            id: contact.id,
            employee_code: contact.employeeCode,
            display_name: contact.displayName,
            extension_number: contact.extensionNumber,
            mobile_number: contact.mobileNumber,
            email: contact.email,
            department_id: contact.departmentId,
            location_id: contact.locationId,
            ip_address: contact.ipAddress,
            status: contact.status
        });
    }

    return wrapper;
}

function createFallbackForm() {
    const wrapper = document.createElement("div");
    const form = document.createElement("form");
    form.dataset.contactForm = "";
    form.className = "cp-admin-form-grid";

    const fields = [
        ["employee_code", "รหัสพนักงาน", "text", false],
        ["display_name", "ชื่อผู้ติดต่อ", "text", true],
        ["extension_number", "เบอร์ต่อ", "text", true],
        ["mobile_number", "เบอร์โทรศัพท์", "tel", false],
        ["email", "อีเมล", "email", false],
        ["ip_address", "IP Address", "text", false]
    ];

    fields.forEach(([name, labelText, type, required]) => {
        const group = document.createElement("label");
        group.className = "cp-form-group";
        const label = document.createElement("span");
        label.textContent = labelText;
        const input = document.createElement("input");
        input.className = "cp-input";
        input.name = name;
        input.type = type;
        input.required = required;
        const error = document.createElement("small");
        error.dataset.fieldError = name;
        error.hidden = true;
        group.append(label, input, error);
        form.appendChild(group);
    });

    form.append(
        createSelectGroup("department_id", "แผนก", true),
        createSelectGroup("location_id", "สถานที่", true),
        createSelectGroup("status", "สถานะ", true, [
            { id: "active", name: "Active" },
            { id: "inactive", name: "Inactive" }
        ])
    );
    wrapper.appendChild(form);
    return wrapper;
}

function createSelectGroup(name, labelText, required, options = []) {
    const group = document.createElement("label");
    group.className = "cp-form-group";
    const label = document.createElement("span");
    label.textContent = labelText;
    const select = document.createElement("select");
    select.className = "cp-select";
    select.name = name;
    select.required = required;
    const error = document.createElement("small");
    error.dataset.fieldError = name;
    error.hidden = true;
    group.append(label, select, error);
    populateSelectElement(select, options, "กรุณาเลือก");
    return group;
}

async function saveContact(wrapper, contactId) {
    const form = wrapper.querySelector(SELECTORS.form) || wrapper.querySelector("form");
    utils.clearFormErrors(form);

    if (!form.reportValidity()) return false;

    const values = utils.serializeForm(form);
    const errors = validateContact(values);

    if (Object.keys(errors).length > 0) {
        utils.applyFormErrors(form, errors);
        form.querySelector("[aria-invalid=\"true\"]")?.focus();
        return false;
    }

    try {
        if (contactId) {
            await api.put(CONFIG.endpoints.update, { ...values, id: contactId }, {
                requestKey: `contact-update-${contactId}`
            });
        } else {
            await api.post(CONFIG.endpoints.create, values, {
                requestKey: "contact-create"
            });
        }

        components.toast.success(contactId ? "แก้ไขผู้ติดต่อแล้ว" : "เพิ่มผู้ติดต่อแล้ว");
        await loadContacts({ silent: true });
        return true;
    } catch (error) {
        if (error.status === 422 && utils.isPlainObject(error.details)) {
            utils.applyFormErrors(form, error.details);
            return false;
        }
        handleError(error, "บันทึกข้อมูลไม่สำเร็จ");
        return false;
    }
}

function validateContact(values) {
    const errors = {};
    if (!utils.validateRequired(values.display_name)) errors.display_name = ["กรุณากรอกชื่อผู้ติดต่อ"];
    if (!utils.validateRequired(values.extension_number)) errors.extension_number = ["กรุณากรอกเบอร์ต่อ"];
    if (values.email && !utils.isValidEmail(values.email)) errors.email = ["รูปแบบอีเมลไม่ถูกต้อง"];
    if (values.ip_address && !utils.isValidIPv4(values.ip_address)) errors.ip_address = ["รูปแบบ IPv4 ไม่ถูกต้อง"];
    if (!values.department_id) errors.department_id = ["กรุณาเลือกแผนก"];
    if (!values.location_id) errors.location_id = ["กรุณาเลือกสถานที่"];
    return errors;
}

async function deleteContact(contactId) {
    permissions.authorize(PERMISSIONS.CONTACTS.DELETE);
    const contact = state.contacts.find((item) => item.id === contactId);
    const confirmed = await components.confirm({
        title: "ยืนยันการลบผู้ติดต่อ",
        message: `ต้องการลบ ${contact?.displayName || "ผู้ติดต่อรายการนี้"} หรือไม่`,
        confirmText: "ลบข้อมูล",
        cancelText: "ยกเลิก",
        variant: "danger"
    });

    if (!confirmed) return;

    try {
        await api.delete(CONFIG.endpoints.remove, {
            query: { id: contactId },
            requestKey: `contact-delete-${contactId}`
        });
        components.toast.success("ลบผู้ติดต่อแล้ว");
        await loadContacts({ silent: true });
    } catch (error) {
        handleError(error, "ลบผู้ติดต่อไม่สำเร็จ");
    }
}

async function restoreContact(contactId) {
    permissions.authorize(PERMISSIONS.CONTACTS.RESTORE);

    try {
        await api.patch(CONFIG.endpoints.restore, { id: contactId }, {
            requestKey: `contact-restore-${contactId}`
        });
        components.toast.success("กู้คืนผู้ติดต่อแล้ว");
        await loadContacts({ silent: true });
    } catch (error) {
        handleError(error, "กู้คืนผู้ติดต่อไม่สำเร็จ");
    }
}

async function exportContacts(button) {
    permissions.authorize(PERMISSIONS.CONTACTS.EXPORT);

    try {
        await components.withButtonLoading(button, async () => {
            const blob = await api.download(CONFIG.endpoints.export, {
                query: {
                    search: state.query.search,
                    department_id: state.query.departmentId,
                    location_id: state.query.locationId,
                    status: state.query.status
                },
                requestKey: "contacts-export"
            });
            utils.downloadBlob(blob, CONFIG.exportFileName);
        }, { text: "กำลังส่งออก..." });
        components.toast.success("ส่งออกข้อมูลเรียบร้อยแล้ว");
    } catch (error) {
        handleError(error, "ส่งออกข้อมูลไม่สำเร็จ");
    }
}

function renderContacts() {
    renderTable();
    renderCards();
    renderPagination();
    setText(SELECTORS.total, utils.formatNumber(state.pagination.total));
    toggleEmpty(state.contacts.length === 0);
    permissions.apply(document);
}

function renderTable() {
    document.querySelectorAll(SELECTORS.tableBody).forEach((body) => {
        const fragment = document.createDocumentFragment();
        state.contacts.forEach((contact) => fragment.appendChild(createTableRow(contact)));
        body.replaceChildren(fragment);
    });
}

function createTableRow(contact) {
    const row = document.createElement("tr");

    const values = [
        contact.employeeCode || "-",
        contact.displayName,
        contact.extensionNumber || "-",
        contact.departmentName || "-",
        contact.locationName || "-"
    ];

    values.forEach((value) => {
        const cell = document.createElement("td");
        cell.textContent = value;
        row.appendChild(cell);
    });

    const statusCell = document.createElement("td");
    const badge = document.createElement("span");
    badge.className = `cp-badge cp-badge--${contact.status === "active" ? "success" : "neutral"}`;
    badge.textContent = contact.status === "active" ? "Active" : "Inactive";
    statusCell.appendChild(badge);

    const actionCell = document.createElement("td");
    actionCell.appendChild(createActions(contact));
    row.append(statusCell, actionCell);
    return row;
}

function renderCards() {
    document.querySelectorAll(SELECTORS.cardList).forEach((container) => {
        const fragment = document.createDocumentFragment();
        state.contacts.forEach((contact) => fragment.appendChild(createContactCard(contact)));
        container.replaceChildren(fragment);
    });
}

function createContactCard(contact) {
    const card = document.createElement("article");
    card.className = "cp-contact-card";

    const name = document.createElement("h3");
    name.textContent = contact.displayName;
    const extension = document.createElement("strong");
    extension.textContent = contact.extensionNumber || "-";
    const meta = document.createElement("p");
    meta.textContent = `${contact.departmentName || "-"} · ${contact.locationName || "-"}`;

    card.append(name, extension, meta, createActions(contact));
    return card;
}

function createActions(contact) {
    const actions = document.createElement("div");
    actions.className = "cp-contact-card__actions";

    if (contact.deletedAt) {
        actions.appendChild(createActionButton("กู้คืน", "contactRestore", contact.id, PERMISSIONS.CONTACTS.RESTORE));
    } else {
        actions.append(
            createActionButton("แก้ไข", "contactEdit", contact.id, PERMISSIONS.CONTACTS.UPDATE),
            createActionButton("ลบ", "contactDelete", contact.id, PERMISSIONS.CONTACTS.DELETE, true)
        );
    }

    return actions;
}

function createActionButton(label, datasetKey, id, permission, danger = false) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = danger
        ? "cp-button cp-button--danger cp-button--small"
        : "cp-button cp-button--secondary cp-button--small";
    button.textContent = label;
    button.dataset[datasetKey] = String(id);
    button.dataset.permission = permission;
    return button;
}

function renderPagination() {
    const { page, totalPages, total, limit } = state.pagination;
    const start = total === 0 ? 0 : ((page - 1) * limit) + 1;
    const end = Math.min(page * limit, total);
    setText(SELECTORS.pageSummary, `${utils.formatNumber(start)}-${utils.formatNumber(end)} จาก ${utils.formatNumber(total)}`);

    document.querySelectorAll(SELECTORS.pagination).forEach((container) => {
        const fragment = document.createDocumentFragment();
        const pages = buildPageNumbers(page, totalPages);
        fragment.appendChild(createPageButton("ก่อนหน้า", page - 1, page <= 1));
        pages.forEach((value) => {
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
        fragment.appendChild(createPageButton("ถัดไป", page + 1, page >= totalPages));
        container.replaceChildren(fragment);
    });
}

function buildPageNumbers(current, total) {
    if (total <= 7) return Array.from({ length: total }, (_, index) => index + 1);
    const pages = [1];
    if (current > 4) pages.push("ellipsis");
    for (let value = Math.max(2, current - 1); value <= Math.min(total - 1, current + 1); value += 1) pages.push(value);
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

function populateSelects() {
    document.querySelectorAll(SELECTORS.departmentFilter).forEach((select) => {
        populateSelectElement(select, state.departments, "ทุกแผนก");
        select.value = state.query.departmentId;
    });
    document.querySelectorAll(SELECTORS.locationFilter).forEach((select) => {
        populateSelectElement(select, state.locations, "ทุกสถานที่");
        select.value = state.query.locationId;
    });
}

function populateFormSelect(form, name, options) {
    const select = form.elements.namedItem(name);
    if (select instanceof HTMLSelectElement) populateSelectElement(select, options, "กรุณาเลือก");
}

function populateSelectElement(select, options, placeholder) {
    const current = select.value;
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
    select.value = current;
}

function resetFilters() {
    state.query = {
        search: "",
        departmentId: "",
        locationId: "",
        status: "",
        sort: "display_name",
        direction: "asc",
        page: 1,
        limit: CONFIG.pageSize
    };

    document.querySelector(SELECTORS.search)?.setAttribute("value", "");
    [SELECTORS.departmentFilter, SELECTORS.locationFilter, SELECTORS.statusFilter].forEach((selector) => {
        const element = document.querySelector(selector);
        if (element) element.value = "";
    });
    const sort = document.querySelector(SELECTORS.sort);
    if (sort) sort.value = "display_name:asc";
    const pageSize = document.querySelector(SELECTORS.pageSize);
    if (pageSize) pageSize.value = String(CONFIG.pageSize);
    const search = document.querySelector(SELECTORS.search);
    if (search) search.value = "";
    loadContacts().catch(() => {});
}

function restoreQueryFromUrl() {
    const params = utils.getQueryParams();
    state.query.search = String(params.search || "");
    state.query.departmentId = String(params.department_id || "");
    state.query.locationId = String(params.location_id || "");
    state.query.status = String(params.status || "");
    state.query.page = Math.max(1, utils.toInteger(params.page, 1));
    state.query.limit = utils.clamp(utils.toInteger(params.limit, CONFIG.pageSize), 10, 100);
}

function syncQueryToUrl() {
    utils.updateQueryParams({
        search: state.query.search,
        department_id: state.query.departmentId,
        location_id: state.query.locationId,
        status: state.query.status,
        page: state.query.page > 1 ? state.query.page : null,
        limit: state.query.limit !== CONFIG.pageSize ? state.query.limit : null
    }, { replace: true });
}

function normalizeContact(value) {
    const id = Number(value?.id || 0);
    if (!Number.isInteger(id) || id < 1) return null;
    return Object.freeze({
        id,
        employeeCode: String(value.employee_code || value.employeeCode || ""),
        displayName: String(value.display_name || value.displayName || "ไม่ระบุชื่อ"),
        extensionNumber: String(value.extension_number || value.extensionNumber || ""),
        mobileNumber: String(value.mobile_number || value.mobileNumber || ""),
        email: String(value.email || ""),
        departmentId: value.department_id || value.departmentId || "",
        departmentName: String(value.department_name || value.departmentName || ""),
        locationId: value.location_id || value.locationId || "",
        locationName: String(value.location_name || value.locationName || ""),
        ipAddress: String(value.ip_address || value.ipAddress || ""),
        status: String(value.status || "active").toLowerCase(),
        deletedAt: value.deleted_at || value.deletedAt || null
    });
}

function normalizeOption(value) {
    const id = value?.id;
    if (id === undefined || id === null || id === "") return null;
    return { id, name: String(value.name || value.display_name || "ไม่ระบุ") };
}

function normalizePagination(meta = {}, itemCount = 0) {
    const limit = utils.clamp(utils.toInteger(meta.limit, state.query.limit), 1, 100);
    const total = Math.max(0, utils.toInteger(meta.total, itemCount));
    const totalPages = Math.max(1, utils.toInteger(meta.totalPages ?? meta.total_pages, Math.ceil(total / limit) || 1));
    const page = utils.clamp(utils.toInteger(meta.page, state.query.page), 1, totalPages);
    return { page, limit, total, totalPages };
}

function extractItems(value, key) {
    if (Array.isArray(value)) return value;
    return value?.[key] || value?.items || [];
}

function setLoading(loading) {
    document.querySelectorAll(SELECTORS.page).forEach((element) => {
        element.setAttribute("aria-busy", String(loading));
        element.classList.toggle("is-loading", loading);
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
        element.hidden = true;
        element.textContent = "";
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
    console.error("[ConnectPro Admin Contacts]", error);
    components.toast.error(error?.message || fallback);
}

document.addEventListener("DOMContentLoaded", initializeContacts, { once: true });

export default Object.freeze({
    init: initializeContacts,
    load: loadContacts,
    openForm: openContactForm,
    remove: deleteContact,
    restore: restoreContact,
    export: exportContacts
});
