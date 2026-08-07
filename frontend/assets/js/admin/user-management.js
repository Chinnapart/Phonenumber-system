/**
 * ConnectPro Admin User Management
 * File: frontend/assets/js/admin/user-management.js
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
        list: "admin/users/list.php",
        detail: "admin/users/detail.php",
        create: "admin/users/create.php",
        update: "admin/users/update.php",
        remove: "admin/users/delete.php",
        changeStatus: "admin/users/change-status.php",
        resetPassword: "admin/users/reset-password.php",
        assignRole: "admin/users/assign-role.php",
        roles: "admin/roles/options.php",
        departments: "departments/list.php"
    }),
    pageSize: 20,
    searchDelay: 350,
    minimumPasswordLength: 8
});

const SELECTORS = Object.freeze({
    page: "[data-admin-users]",
    tableBody: "[data-users-table-body]",
    cardList: "[data-users-card-list]",
    empty: "[data-users-empty]",
    loading: "[data-users-loading]",
    error: "[data-users-error]",
    search: "[data-user-search]",
    roleFilter: "[data-user-role-filter]",
    departmentFilter: "[data-user-department-filter]",
    statusFilter: "[data-user-status-filter]",
    pageSize: "[data-user-page-size]",
    total: "[data-users-total]",
    pageSummary: "[data-users-page-summary]",
    pagination: "[data-users-pagination]",
    addButton: "[data-user-add]",
    refreshButton: "[data-users-refresh]",
    resetButton: "[data-users-reset]",
    formTemplate: "#userFormTemplate",
    form: "[data-user-form]",
    logoutButton: "[data-logout]"
});

const state = {
    initialized: false,
    loading: false,
    currentUserId: null,
    users: [],
    roles: [],
    departments: [],
    query: {
        search: "",
        roleId: "",
        departmentId: "",
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

async function initializeUserManagement() {
    if (state.initialized) return;
    state.initialized = true;

    components.init();
    auth.init();

    try {
        const currentUser = await auth.requireAuth({ roles: ["admin"] });
        if (!currentUser) return;

        state.currentUserId = currentUser.id;
        permissions.init();
        permissions.authorize(PERMISSIONS.USERS.VIEW);
        auth.hydrateUserElements(document, currentUser);
        restoreQueryFromUrl();
        syncControls();
        bindEvents();

        await Promise.allSettled([
            loadOptions(),
            notifications.init({ showToastForNew: true })
        ]);

        await loadUsers();
    } catch (error) {
        handleError(error, "ไม่สามารถเริ่มต้นหน้าจัดการผู้ใช้ได้");
    }
}

export async function loadUsers(options = {}) {
    if (state.loading) return;

    state.loading = true;
    setLoading(true);
    hideError();

    try {
        const response = await api.get(CONFIG.endpoints.list, {
            query: {
                search: state.query.search,
                role_id: state.query.roleId,
                department_id: state.query.departmentId,
                account_status: state.query.status,
                page: state.query.page,
                limit: state.query.limit,
                sort: "display_name",
                direction: "asc"
            },
            requestKey: "admin-users-list",
            cancelPrevious: true,
            returnMeta: true
        });

        const payload = response.data || {};
        const items = Array.isArray(payload)
            ? payload
            : payload.users || payload.items || [];

        state.users = items.map(normalizeUser).filter(Boolean);
        state.pagination = normalizePagination(
            response.meta || payload.meta || payload.pagination,
            state.users.length
        );
        state.query.page = state.pagination.page;

        renderUsers();
        syncQueryToUrl();

        if (options.showSuccess) {
            components.toast.success("อัปเดตรายชื่อผู้ใช้แล้ว", { duration: 2000 });
        }
    } catch (error) {
        if (!(error instanceof ApiError && error.isCancelled)) {
            showError(error.message || "โหลดรายชื่อผู้ใช้ไม่สำเร็จ");
            if (!options.silent) handleError(error, "โหลดรายชื่อผู้ใช้ไม่สำเร็จ");
        }
    } finally {
        state.loading = false;
        setLoading(false);
    }
}

async function loadOptions() {
    const [rolesResult, departmentsResult] = await Promise.allSettled([
        api.get(CONFIG.endpoints.roles, {
            requestKey: "user-management-roles"
        }),
        api.get(CONFIG.endpoints.departments, {
            query: { status: "active", limit: 500 },
            requestKey: "user-management-departments"
        })
    ]);

    if (rolesResult.status === "fulfilled") {
        state.roles = extractItems(rolesResult.value, "roles")
            .map(normalizeOption)
            .filter(Boolean);
    }

    if (departmentsResult.status === "fulfilled") {
        state.departments = extractItems(departmentsResult.value, "departments")
            .map(normalizeOption)
            .filter(Boolean);
    }

    populateSelect(SELECTORS.roleFilter, state.roles, "ทุก Role", state.query.roleId);
    populateSelect(
        SELECTORS.departmentFilter,
        state.departments,
        "ทุกแผนก",
        state.query.departmentId
    );
}

function bindEvents() {
    const searchHandler = utils.debounce((value) => {
        state.query.search = utils.normalizeText(value);
        state.query.page = 1;
        loadUsers().catch(() => {});
    }, CONFIG.searchDelay);

    document.querySelector(SELECTORS.search)?.addEventListener("input", (event) => {
        searchHandler(event.target.value);
    });

    bindFilter(SELECTORS.roleFilter, "roleId");
    bindFilter(SELECTORS.departmentFilter, "departmentId");
    bindFilter(SELECTORS.statusFilter, "status");

    document.querySelector(SELECTORS.pageSize)?.addEventListener("change", (event) => {
        state.query.limit = utils.clamp(
            utils.toInteger(event.target.value, CONFIG.pageSize),
            10,
            100
        );
        state.query.page = 1;
        loadUsers().catch(() => {});
    });

    document.querySelectorAll(SELECTORS.addButton).forEach((button) => {
        button.addEventListener("click", () => openUserForm());
    });

    document.querySelectorAll(SELECTORS.refreshButton).forEach((button) => {
        button.addEventListener("click", () => {
            components.withButtonLoading(
                button,
                () => loadUsers({ showSuccess: true }),
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

function bindFilter(selector, key) {
    document.querySelector(selector)?.addEventListener("change", (event) => {
        state.query[key] = event.target.value;
        state.query.page = 1;
        loadUsers().catch(() => {});
    });
}

async function handleDelegatedClick(event) {
    const editButton = event.target.closest("[data-user-edit]");
    const statusButton = event.target.closest("[data-user-status]");
    const passwordButton = event.target.closest("[data-user-reset-password]");
    const roleButton = event.target.closest("[data-user-assign-role]");
    const deleteButton = event.target.closest("[data-user-delete]");
    const pageButton = event.target.closest("[data-page]");

    try {
        if (editButton) {
            await openUserForm(utils.toInteger(editButton.dataset.userEdit));
        } else if (statusButton) {
            await changeUserStatus(
                utils.toInteger(statusButton.dataset.userId),
                statusButton.dataset.userStatus
            );
        } else if (passwordButton) {
            await openResetPasswordDialog(
                utils.toInteger(passwordButton.dataset.userResetPassword)
            );
        } else if (roleButton) {
            await openAssignRoleDialog(
                utils.toInteger(roleButton.dataset.userAssignRole)
            );
        } else if (deleteButton) {
            await deleteUser(utils.toInteger(deleteButton.dataset.userDelete));
        } else if (pageButton && !pageButton.disabled) {
            state.query.page = utils.toInteger(pageButton.dataset.page, 1);
            await loadUsers();
        }
    } catch (error) {
        handleError(error, "ดำเนินการไม่สำเร็จ");
    }
}

export async function openUserForm(userId = null) {
    const editing = Number.isInteger(userId) && userId > 0;
    permissions.authorize(editing ? PERMISSIONS.USERS.UPDATE : PERMISSIONS.USERS.CREATE);

    let user = null;
    if (editing) {
        const response = await api.get(CONFIG.endpoints.detail, {
            query: { id: userId },
            requestKey: `user-detail-${userId}`
        });
        user = normalizeUser(response?.user || response);
    }

    const content = buildUserForm(user);
    const modal = components.openModal({
        title: editing ? "แก้ไขผู้ใช้" : "เพิ่มผู้ใช้",
        content,
        className: "cp-glass-modal--large",
        closeOnBackdrop: false,
        actions: [
            {
                label: "ยกเลิก",
                className: "cp-button cp-button--secondary",
                onClick: () => modal.close("cancel")
            },
            {
                label: editing ? "บันทึกการแก้ไข" : "เพิ่มผู้ใช้",
                className: "cp-button cp-button--primary",
                loadingText: "กำลังบันทึก...",
                autofocus: true,
                onClick: async () => {
                    const saved = await saveUser(content, userId);
                    if (saved) modal.close("saved");
                }
            }
        ]
    });
}

function buildUserForm(user) {
    const template = document.querySelector(SELECTORS.formTemplate);
    let wrapper;

    if (template instanceof HTMLTemplateElement) {
        wrapper = document.createElement("div");
        wrapper.appendChild(template.content.cloneNode(true));
    } else {
        wrapper = createFallbackUserForm(Boolean(user));
    }

    const form = wrapper.querySelector(SELECTORS.form) || wrapper.querySelector("form");
    if (!form) throw new Error("ไม่พบ User Form");

    populateFormSelect(form, "role_id", state.roles, "กรุณาเลือก Role");
    populateFormSelect(form, "department_id", state.departments, "กรุณาเลือกแผนก");

    const passwordField = form.elements.namedItem("password");
    if (passwordField instanceof HTMLInputElement) {
        passwordField.required = !user;
        if (user) passwordField.closest(".cp-form-group")?.remove();
    }

    if (user) {
        utils.setFormValues(form, {
            username: user.username,
            display_name: user.displayName,
            employee_code: user.employeeCode,
            email: user.email,
            role_id: user.roleId,
            department_id: user.departmentId,
            account_status: user.accountStatus
        });

        const username = form.elements.namedItem("username");
        if (username instanceof HTMLInputElement) username.readOnly = true;
    }

    return wrapper;
}

function createFallbackUserForm(editing) {
    const wrapper = document.createElement("div");
    const form = document.createElement("form");
    form.dataset.userForm = "";
    form.className = "cp-admin-form-grid";

    form.append(
        createInputGroup("username", "ชื่อผู้ใช้", "text", true),
        createInputGroup("display_name", "ชื่อที่แสดง", "text", true),
        createInputGroup("employee_code", "รหัสพนักงาน", "text", false),
        createInputGroup("email", "อีเมล", "email", false)
    );

    if (!editing) {
        form.appendChild(createInputGroup("password", "รหัสผ่านเริ่มต้น", "password", true));
    }

    form.append(
        createSelectGroup("role_id", "Role"),
        createSelectGroup("department_id", "แผนก"),
        createSelectGroup("account_status", "สถานะบัญชี", [
            { id: "active", name: "Active" },
            { id: "inactive", name: "Inactive" },
            { id: "locked", name: "Locked" },
            { id: "suspended", name: "Suspended" }
        ])
    );

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
    input.autocomplete = type === "password" ? "new-password" : "off";
    const error = document.createElement("small");
    error.dataset.fieldError = name;
    error.hidden = true;
    group.append(label, input, error);
    return group;
}

function createSelectGroup(name, labelText, options = []) {
    const group = document.createElement("label");
    group.className = "cp-form-group";
    const label = document.createElement("span");
    label.textContent = labelText;
    const select = document.createElement("select");
    select.name = name;
    select.className = "cp-select";
    select.required = true;
    const error = document.createElement("small");
    error.dataset.fieldError = name;
    error.hidden = true;
    group.append(label, select, error);
    populateSelectElement(select, options, "กรุณาเลือก");
    return group;
}

async function saveUser(wrapper, userId) {
    const form = wrapper.querySelector(SELECTORS.form) || wrapper.querySelector("form");
    utils.clearFormErrors(form);
    if (!form.reportValidity()) return false;

    const values = utils.serializeForm(form);
    const errors = validateUser(values, Boolean(userId));

    if (Object.keys(errors).length > 0) {
        utils.applyFormErrors(form, errors);
        form.querySelector("[aria-invalid=\"true\"]")?.focus();
        return false;
    }

    const payload = {
        username: utils.normalizeText(values.username).toLowerCase(),
        display_name: utils.normalizeText(values.display_name),
        employee_code: utils.normalizeText(values.employee_code),
        email: utils.normalizeText(values.email),
        role_id: utils.toInteger(values.role_id),
        department_id: utils.toInteger(values.department_id),
        account_status: values.account_status || "active"
    };

    if (!userId) payload.password = String(values.password || "");

    try {
        if (userId) {
            await api.put(CONFIG.endpoints.update, { ...payload, id: userId }, {
                requestKey: `user-update-${userId}`
            });
        } else {
            await api.post(CONFIG.endpoints.create, payload, {
                requestKey: "user-create"
            });
        }

        components.toast.success(userId ? "แก้ไขผู้ใช้แล้ว" : "เพิ่มผู้ใช้แล้ว");
        await loadUsers({ silent: true });
        return true;
    } catch (error) {
        if (error.status === 422 && utils.isPlainObject(error.details)) {
            utils.applyFormErrors(form, error.details);
            return false;
        }
        handleError(error, "บันทึกข้อมูลผู้ใช้ไม่สำเร็จ");
        return false;
    }
}

function validateUser(values, editing) {
    const errors = {};
    const username = utils.normalizeText(values.username);
    const displayName = utils.normalizeText(values.display_name);

    if (!/^[a-zA-Z0-9._-]{3,50}$/.test(username)) {
        errors.username = ["ชื่อผู้ใช้ต้องมี 3-50 ตัว และใช้ตัวอักษร ตัวเลข . _ -"];
    }
    if (!utils.validateLength(displayName, { min: 2, max: 100 })) {
        errors.display_name = ["ชื่อที่แสดงต้องมี 2-100 ตัวอักษร"];
    }
    if (values.email && !utils.isValidEmail(values.email)) {
        errors.email = ["รูปแบบอีเมลไม่ถูกต้อง"];
    }
    if (!editing && String(values.password || "").length < CONFIG.minimumPasswordLength) {
        errors.password = [`รหัสผ่านต้องมีอย่างน้อย ${CONFIG.minimumPasswordLength} ตัวอักษร`];
    }
    if (!utils.toInteger(values.role_id)) errors.role_id = ["กรุณาเลือก Role"];
    if (!utils.toInteger(values.department_id)) errors.department_id = ["กรุณาเลือกแผนก"];
    return errors;
}

export async function changeUserStatus(userId, status) {
    const permission = status === "active"
        ? PERMISSIONS.USERS.ACTIVATE
        : PERMISSIONS.USERS.DEACTIVATE;
    permissions.authorize(permission);
    assertMutableUser(userId);

    const labels = {
        active: "เปิดใช้งาน",
        inactive: "ปิดใช้งาน",
        locked: "ล็อก",
        suspended: "ระงับ"
    };

    if (!Object.hasOwn(labels, status)) throw new TypeError("สถานะบัญชีไม่ถูกต้อง");

    const user = state.users.find((item) => item.id === userId);
    const confirmed = await components.confirm({
        title: `ยืนยันการ${labels[status]}บัญชี`,
        message: `ต้องการ${labels[status]}บัญชี ${user?.displayName || "ผู้ใช้นี้"} หรือไม่`,
        confirmText: "ยืนยัน",
        cancelText: "ยกเลิก",
        variant: status === "active" ? "primary" : "danger"
    });

    if (!confirmed) return false;

    await api.patch(CONFIG.endpoints.changeStatus, {
        id: userId,
        account_status: status
    }, {
        requestKey: `user-status-${userId}`
    });

    components.toast.success("เปลี่ยนสถานะบัญชีแล้ว");
    await loadUsers({ silent: true });
    return true;
}

export async function openResetPasswordDialog(userId) {
    permissions.authorize(PERMISSIONS.USERS.RESET_PASSWORD);
    assertMutableUser(userId);

    const form = document.createElement("form");
    form.className = "cp-admin-form-grid";
    form.append(
        createInputGroup("password", "รหัสผ่านใหม่", "password", true),
        createInputGroup("password_confirmation", "ยืนยันรหัสผ่านใหม่", "password", true)
    );

    const modal = components.openModal({
        title: "รีเซ็ตรหัสผ่าน",
        content: form,
        closeOnBackdrop: false,
        actions: [
            {
                label: "ยกเลิก",
                className: "cp-button cp-button--secondary",
                onClick: () => modal.close("cancel")
            },
            {
                label: "รีเซ็ตรหัสผ่าน",
                className: "cp-button cp-button--primary",
                loadingText: "กำลังรีเซ็ต...",
                autofocus: true,
                onClick: async () => {
                    const saved = await resetPassword(userId, form);
                    if (saved) modal.close("saved");
                }
            }
        ]
    });
}

async function resetPassword(userId, form) {
    utils.clearFormErrors(form);
    if (!form.reportValidity()) return false;

    const values = utils.serializeForm(form);
    const password = String(values.password || "");
    const confirmation = String(values.password_confirmation || "");
    const errors = {};

    if (password.length < CONFIG.minimumPasswordLength) {
        errors.password = [`รหัสผ่านต้องมีอย่างน้อย ${CONFIG.minimumPasswordLength} ตัวอักษร`];
    }
    if (password !== confirmation) {
        errors.password_confirmation = ["รหัสผ่านยืนยันไม่ตรงกัน"];
    }
    if (Object.keys(errors).length > 0) {
        utils.applyFormErrors(form, errors);
        return false;
    }

    try {
        await api.patch(CONFIG.endpoints.resetPassword, {
            id: userId,
            password,
            password_confirmation: confirmation
        }, {
            requestKey: `user-password-${userId}`
        });
        components.toast.success("รีเซ็ตรหัสผ่านแล้ว");
        return true;
    } catch (error) {
        if (error.status === 422 && utils.isPlainObject(error.details)) {
            utils.applyFormErrors(form, error.details);
            return false;
        }
        handleError(error, "รีเซ็ตรหัสผ่านไม่สำเร็จ");
        return false;
    }
}

export async function openAssignRoleDialog(userId) {
    permissions.authorize(PERMISSIONS.USERS.ASSIGN_ROLE);
    assertMutableUser(userId);
    const user = state.users.find((item) => item.id === userId);

    const form = document.createElement("form");
    form.className = "cp-admin-form-grid";
    const group = createSelectGroup("role_id", "Role");
    form.appendChild(group);
    populateFormSelect(form, "role_id", state.roles, "กรุณาเลือก Role");
    utils.setFormValues(form, { role_id: user?.roleId || "" });

    const modal = components.openModal({
        title: "กำหนด Role",
        content: form,
        closeOnBackdrop: false,
        actions: [
            {
                label: "ยกเลิก",
                className: "cp-button cp-button--secondary",
                onClick: () => modal.close("cancel")
            },
            {
                label: "บันทึก Role",
                className: "cp-button cp-button--primary",
                loadingText: "กำลังบันทึก...",
                onClick: async () => {
                    const roleId = utils.toInteger(utils.serializeForm(form).role_id);
                    if (!roleId) {
                        utils.applyFormErrors(form, { role_id: ["กรุณาเลือก Role"] });
                        return;
                    }
                    await api.patch(CONFIG.endpoints.assignRole, {
                        id: userId,
                        role_id: roleId
                    }, { requestKey: `user-role-${userId}` });
                    components.toast.success("กำหนด Role แล้ว");
                    await loadUsers({ silent: true });
                    modal.close("saved");
                }
            }
        ]
    });
}

export async function deleteUser(userId) {
    permissions.authorize(PERMISSIONS.USERS.DELETE);
    assertMutableUser(userId);
    const user = state.users.find((item) => item.id === userId);

    const confirmed = await components.confirm({
        title: "ยืนยันการลบผู้ใช้",
        message: `ต้องการลบบัญชี ${user?.displayName || "ผู้ใช้นี้"} หรือไม่`,
        confirmText: "ลบบัญชี",
        cancelText: "ยกเลิก",
        variant: "danger"
    });

    if (!confirmed) return false;

    await api.delete(CONFIG.endpoints.remove, {
        query: { id: userId },
        requestKey: `user-delete-${userId}`
    });
    components.toast.success("ลบบัญชีผู้ใช้แล้ว");
    await loadUsers({ silent: true });
    return true;
}

function renderUsers() {
    renderTable();
    renderCards();
    renderPagination();
    setText(SELECTORS.total, utils.formatNumber(state.pagination.total));
    setHidden(SELECTORS.empty, state.users.length > 0);
    permissions.apply(document);
}

function renderTable() {
    document.querySelectorAll(SELECTORS.tableBody).forEach((body) => {
        const fragment = document.createDocumentFragment();
        state.users.forEach((user) => fragment.appendChild(createUserRow(user)));
        body.replaceChildren(fragment);
    });
}

function createUserRow(user) {
    const row = document.createElement("tr");
    row.dataset.userId = String(user.id);

    const identityCell = document.createElement("td");
    const identity = document.createElement("div");
    identity.className = "cp-user-contact-row__identity";
    const avatar = document.createElement("span");
    avatar.className = "cp-glass-avatar";
    avatar.textContent = utils.getInitials(user.displayName);
    const text = document.createElement("div");
    const name = document.createElement("strong");
    name.textContent = user.displayName;
    const username = document.createElement("small");
    username.textContent = user.username;
    text.append(name, username);
    identity.append(avatar, text);
    identityCell.appendChild(identity);

    const employeeCell = createCell(user.employeeCode || "-");
    const departmentCell = createCell(user.departmentName || "-");
    const roleCell = createCell(user.roleName || "-");
    const statusCell = document.createElement("td");
    statusCell.appendChild(createStatusBadge(user.accountStatus));
    const loginCell = createCell(utils.formatDateTime(user.lastLoginAt));
    const actionsCell = document.createElement("td");
    actionsCell.appendChild(createUserActions(user));

    row.append(
        identityCell,
        employeeCell,
        departmentCell,
        roleCell,
        statusCell,
        loginCell,
        actionsCell
    );
    return row;
}

function renderCards() {
    document.querySelectorAll(SELECTORS.cardList).forEach((container) => {
        const fragment = document.createDocumentFragment();

        state.users.forEach((user) => {
            const card = document.createElement("article");
            card.className = "cp-contact-card";
            const name = document.createElement("h3");
            name.textContent = user.displayName;
            const username = document.createElement("p");
            username.textContent = `@${user.username}`;
            const meta = document.createElement("p");
            meta.textContent = `${user.roleName || "-"} · ${user.departmentName || "-"}`;
            card.append(name, username, meta, createStatusBadge(user.accountStatus), createUserActions(user));
            fragment.appendChild(card);
        });

        container.replaceChildren(fragment);
    });
}

function createUserActions(user) {
    const actions = document.createElement("div");
    actions.className = "cp-contact-card__actions";
    const isSelf = user.id === state.currentUserId;

    actions.append(
        createActionButton("แก้ไข", "userEdit", user.id, PERMISSIONS.USERS.UPDATE),
        createActionButton("Role", "userAssignRole", user.id, PERMISSIONS.USERS.ASSIGN_ROLE, false, isSelf),
        createActionButton("รีเซ็ตรหัส", "userResetPassword", user.id, PERMISSIONS.USERS.RESET_PASSWORD, false, isSelf)
    );

    if (user.accountStatus === "active") {
        actions.appendChild(createStatusButton(user.id, "inactive", "ปิดใช้งาน", isSelf));
    } else {
        actions.appendChild(createStatusButton(user.id, "active", "เปิดใช้งาน", isSelf));
    }

    actions.appendChild(
        createActionButton("ลบ", "userDelete", user.id, PERMISSIONS.USERS.DELETE, true, isSelf)
    );
    return actions;
}

function createActionButton(label, datasetKey, id, permission, danger = false, disabled = false) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = danger
        ? "cp-button cp-button--danger cp-button--small"
        : "cp-button cp-button--secondary cp-button--small";
    button.textContent = label;
    button.dataset[datasetKey] = String(id);
    button.dataset.permission = permission;
    button.disabled = disabled;
    if (disabled) button.title = "ไม่สามารถดำเนินการกับบัญชีที่กำลังใช้งาน";
    return button;
}

function createStatusButton(id, status, label, disabled) {
    const permission = status === "active"
        ? PERMISSIONS.USERS.ACTIVATE
        : PERMISSIONS.USERS.DEACTIVATE;
    const button = createActionButton(label, "userStatus", status, permission, status !== "active", disabled);
    button.dataset.userId = String(id);
    return button;
}

function createStatusBadge(status) {
    const variants = {
        active: "success",
        inactive: "neutral",
        locked: "danger",
        suspended: "warning"
    };
    const badge = document.createElement("span");
    badge.className = `cp-badge cp-badge--${variants[status] || "neutral"}`;
    badge.textContent = utils.capitalize(status || "unknown");
    return badge;
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
    setText(SELECTORS.pageSummary, `${utils.formatNumber(start)}-${utils.formatNumber(end)} จาก ${utils.formatNumber(total)}`);

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

function populateSelect(selector, options, placeholder, selected) {
    document.querySelectorAll(selector).forEach((select) => {
        populateSelectElement(select, options, placeholder);
        select.value = String(selected || "");
    });
}

function populateFormSelect(form, name, options, placeholder) {
    const select = form.elements.namedItem(name);
    if (select instanceof HTMLSelectElement) {
        populateSelectElement(select, options, placeholder);
    }
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

function restoreQueryFromUrl() {
    const params = utils.getQueryParams();
    state.query.search = String(params.search || "");
    state.query.roleId = String(params.role_id || "");
    state.query.departmentId = String(params.department_id || "");
    state.query.status = String(params.account_status || "");
    state.query.page = Math.max(1, utils.toInteger(params.page, 1));
    state.query.limit = utils.clamp(utils.toInteger(params.limit, CONFIG.pageSize), 10, 100);
}

function syncControls() {
    setControlValue(SELECTORS.search, state.query.search);
    setControlValue(SELECTORS.roleFilter, state.query.roleId);
    setControlValue(SELECTORS.departmentFilter, state.query.departmentId);
    setControlValue(SELECTORS.statusFilter, state.query.status);
    setControlValue(SELECTORS.pageSize, state.query.limit);
}

function syncQueryToUrl() {
    utils.updateQueryParams({
        search: state.query.search,
        role_id: state.query.roleId,
        department_id: state.query.departmentId,
        account_status: state.query.status,
        page: state.query.page > 1 ? state.query.page : null,
        limit: state.query.limit !== CONFIG.pageSize ? state.query.limit : null
    }, { replace: true });
}

function resetFilters() {
    state.query = {
        search: "",
        roleId: "",
        departmentId: "",
        status: "",
        page: 1,
        limit: CONFIG.pageSize
    };
    syncControls();
    loadUsers().catch(() => {});
}

function normalizeUser(value) {
    const id = Number(value?.id || value?.user_id || 0);
    if (!Number.isInteger(id) || id < 1) return null;

    return Object.freeze({
        id,
        username: String(value.username || ""),
        displayName: String(value.display_name || value.displayName || value.username || "ไม่ระบุชื่อ"),
        employeeCode: String(value.employee_code || value.employeeCode || ""),
        email: String(value.email || ""),
        roleId: value.role_id || value.roleId || "",
        roleName: String(value.role_name || value.roleName || value.role || ""),
        departmentId: value.department_id || value.departmentId || "",
        departmentName: String(value.department_name || value.departmentName || ""),
        accountStatus: String(value.account_status || value.accountStatus || "active").toLowerCase(),
        lastLoginAt: value.last_login_at || value.lastLoginAt || null
    });
}

function normalizeOption(value) {
    const id = value?.id ?? value?.role_id ?? value?.department_id;
    if (id === undefined || id === null || id === "") return null;
    return {
        id,
        name: String(value.name || value.display_name || value.role_name || value.department_name || "ไม่ระบุ")
    };
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

function assertMutableUser(userId) {
    if (!Number.isInteger(userId) || userId < 1) throw new TypeError("User ID ไม่ถูกต้อง");
    if (userId === state.currentUserId) {
        throw new Error("ไม่สามารถดำเนินการนี้กับบัญชีที่กำลังใช้งานอยู่");
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
    console.error("[ConnectPro Admin User Management]", error);
    components.toast.error(error?.message || fallback);
}

document.addEventListener("DOMContentLoaded", initializeUserManagement, { once: true });

export default Object.freeze({
    init: initializeUserManagement,
    load: loadUsers,
    openForm: openUserForm,
    changeStatus: changeUserStatus,
    resetPassword: openResetPasswordDialog,
    assignRole: openAssignRoleDialog,
    remove: deleteUser
});
