/**
 * ConnectPro Admin Activity Log
 * File: frontend/assets/js/admin/activity-log.js
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
        list: "admin/activity-log/list.php",
        detail: "admin/activity-log/detail.php",
        export: "admin/activity-log/export.php",
        remove: "admin/activity-log/delete.php",
        actions: "admin/activity-log/actions.php",
        users: "admin/users/options.php"
    }),
    pageSize: 25,
    searchDelay: 350,
    exportFileName: "connectpro-activity-log.xlsx"
});

const SELECTORS = Object.freeze({
    page: "[data-admin-activity-log]",
    tableBody: "[data-activity-log-body]",
    cardList: "[data-activity-log-cards]",
    empty: "[data-activity-log-empty]",
    loading: "[data-activity-log-loading]",
    error: "[data-activity-log-error]",
    search: "[data-activity-log-search]",
    actionFilter: "[data-activity-log-action]",
    userFilter: "[data-activity-log-user]",
    statusFilter: "[data-activity-log-status]",
    dateFrom: "[data-activity-log-date-from]",
    dateTo: "[data-activity-log-date-to]",
    pageSize: "[data-activity-log-page-size]",
    total: "[data-activity-log-total]",
    pageSummary: "[data-activity-log-page-summary]",
    pagination: "[data-activity-log-pagination]",
    refreshButton: "[data-activity-log-refresh]",
    resetButton: "[data-activity-log-reset]",
    exportButton: "[data-activity-log-export]",
    logoutButton: "[data-logout]"
});

const state = {
    initialized: false,
    loading: false,
    logs: [],
    actions: [],
    users: [],
    query: {
        search: "",
        action: "",
        userId: "",
        status: "",
        dateFrom: "",
        dateTo: "",
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

async function initializeActivityLog() {
    if (state.initialized) return;
    state.initialized = true;

    components.init();
    auth.init();

    try {
        const user = await auth.requireAuth({ roles: ["admin"] });
        if (!user) return;

        permissions.init();
        permissions.authorize(PERMISSIONS.ACTIVITY_LOG.VIEW);
        auth.hydrateUserElements(document, user);
        restoreQueryFromUrl();
        syncControls();
        bindEvents();

        await Promise.allSettled([
            loadFilterOptions(),
            notifications.init({ showToastForNew: true })
        ]);

        await loadActivityLogs();
    } catch (error) {
        handleError(error, "ไม่สามารถเริ่มต้นหน้าประวัติการใช้งานได้");
    }
}

export async function loadActivityLogs(options = {}) {
    if (state.loading) return;

    state.loading = true;
    setLoading(true);
    hideError();

    try {
        validateDateRange();

        const response = await api.get(CONFIG.endpoints.list, {
            query: {
                search: state.query.search,
                action: state.query.action,
                user_id: state.query.userId,
                status: state.query.status,
                date_from: state.query.dateFrom,
                date_to: state.query.dateTo,
                page: state.query.page,
                limit: state.query.limit,
                sort: "created_at",
                direction: "desc"
            },
            requestKey: "admin-activity-log-list",
            cancelPrevious: true,
            returnMeta: true
        });

        const payload = response.data || {};
        const items = Array.isArray(payload)
            ? payload
            : payload.logs || payload.activities || payload.items || [];

        state.logs = items.map(normalizeLog).filter(Boolean);
        state.pagination = normalizePagination(
            response.meta || payload.meta || payload.pagination,
            state.logs.length
        );
        state.query.page = state.pagination.page;

        renderActivityLogs();
        syncQueryToUrl();

        if (options.showSuccess) {
            components.toast.success("อัปเดตประวัติการใช้งานแล้ว", {
                duration: 2000
            });
        }
    } catch (error) {
        if (!(error instanceof ApiError && error.isCancelled)) {
            showError(error.message || "โหลดประวัติการใช้งานไม่สำเร็จ");
            if (!options.silent) {
                handleError(error, "โหลดประวัติการใช้งานไม่สำเร็จ");
            }
        }
    } finally {
        state.loading = false;
        setLoading(false);
    }
}

async function loadFilterOptions() {
    const [actionsResult, usersResult] = await Promise.allSettled([
        api.get(CONFIG.endpoints.actions, {
            requestKey: "activity-log-actions"
        }),
        api.get(CONFIG.endpoints.users, {
            query: { status: "active", limit: 500 },
            requestKey: "activity-log-users"
        })
    ]);

    if (actionsResult.status === "fulfilled") {
        state.actions = extractItems(actionsResult.value, "actions")
            .map(normalizeActionOption)
            .filter(Boolean);
    }

    if (usersResult.status === "fulfilled") {
        state.users = extractItems(usersResult.value, "users")
            .map(normalizeUserOption)
            .filter(Boolean);
    }

    populateSelect(
        SELECTORS.actionFilter,
        state.actions,
        "ทุกกิจกรรม",
        state.query.action
    );
    populateSelect(
        SELECTORS.userFilter,
        state.users,
        "ผู้ใช้ทั้งหมด",
        state.query.userId
    );
}

function bindEvents() {
    const searchHandler = utils.debounce((value) => {
        state.query.search = utils.normalizeText(value);
        state.query.page = 1;
        loadActivityLogs().catch(() => {});
    }, CONFIG.searchDelay);

    document.querySelector(SELECTORS.search)?.addEventListener("input", (event) => {
        searchHandler(event.target.value);
    });

    bindFilter(SELECTORS.actionFilter, "action");
    bindFilter(SELECTORS.userFilter, "userId");
    bindFilter(SELECTORS.statusFilter, "status");
    bindFilter(SELECTORS.dateFrom, "dateFrom");
    bindFilter(SELECTORS.dateTo, "dateTo");

    document.querySelector(SELECTORS.pageSize)?.addEventListener("change", (event) => {
        state.query.limit = utils.clamp(
            utils.toInteger(event.target.value, CONFIG.pageSize),
            10,
            100
        );
        state.query.page = 1;
        loadActivityLogs().catch(() => {});
    });

    document.querySelectorAll(SELECTORS.refreshButton).forEach((button) => {
        button.addEventListener("click", () => {
            components.withButtonLoading(
                button,
                () => loadActivityLogs({ showSuccess: true }),
                { text: "กำลังอัปเดต..." }
            ).catch(() => {});
        });
    });

    document.querySelectorAll(SELECTORS.resetButton).forEach((button) => {
        button.addEventListener("click", resetFilters);
    });

    document.querySelectorAll(SELECTORS.exportButton).forEach((button) => {
        button.addEventListener("click", () => exportActivityLogs(button));
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
        loadActivityLogs().catch(() => {});
    });
}

async function handleDelegatedClick(event) {
    const detailButton = event.target.closest("[data-activity-log-detail]");
    const deleteButton = event.target.closest("[data-activity-log-delete]");
    const pageButton = event.target.closest("[data-page]");

    try {
        if (detailButton) {
            await showLogDetail(
                utils.toInteger(detailButton.dataset.activityLogDetail)
            );
        } else if (deleteButton) {
            await deleteActivityLog(
                utils.toInteger(deleteButton.dataset.activityLogDelete)
            );
        } else if (pageButton && !pageButton.disabled) {
            state.query.page = utils.toInteger(pageButton.dataset.page, 1);
            await loadActivityLogs();
        }
    } catch (error) {
        handleError(error, "ดำเนินการไม่สำเร็จ");
    }
}

export async function showLogDetail(logId) {
    permissions.authorize(PERMISSIONS.ACTIVITY_LOG.VIEW);
    assertValidId(logId);

    const response = await api.get(CONFIG.endpoints.detail, {
        query: { id: logId },
        requestKey: `activity-log-detail-${logId}`
    });
    const log = normalizeLog(response?.log || response?.activity || response);

    if (!log) throw new Error("ไม่พบข้อมูลประวัติการใช้งาน");

    components.openModal({
        title: "รายละเอียดกิจกรรม",
        content: createDetailContent(log),
        className: "cp-glass-modal--large"
    });
}

export async function deleteActivityLog(logId) {
    permissions.authorize(PERMISSIONS.ACTIVITY_LOG.DELETE);
    assertValidId(logId);

    const confirmed = await components.confirm({
        title: "ยืนยันการลบ Log",
        message: "ต้องการลบรายการประวัติการใช้งานนี้หรือไม่",
        confirmText: "ลบ Log",
        cancelText: "ยกเลิก",
        variant: "danger"
    });

    if (!confirmed) return false;

    await api.delete(CONFIG.endpoints.remove, {
        query: { id: logId },
        requestKey: `activity-log-delete-${logId}`
    });

    components.toast.success("ลบรายการ Log แล้ว");
    await loadActivityLogs({ silent: true });
    return true;
}

export async function exportActivityLogs(button = null) {
    permissions.authorize(PERMISSIONS.ACTIVITY_LOG.EXPORT);
    validateDateRange();

    try {
        await runWithOptionalButtonLoading(button, async () => {
            const blob = await api.download(CONFIG.endpoints.export, {
                query: {
                    search: state.query.search,
                    action: state.query.action,
                    user_id: state.query.userId,
                    status: state.query.status,
                    date_from: state.query.dateFrom,
                    date_to: state.query.dateTo
                },
                requestKey: "activity-log-export",
                timeout: 120000
            });

            utils.downloadBlob(blob, CONFIG.exportFileName);
        }, { text: "กำลังส่งออก..." });

        components.toast.success("ส่งออก Activity Log แล้ว");
    } catch (error) {
        handleError(error, "ส่งออก Activity Log ไม่สำเร็จ");
    }
}

function renderActivityLogs() {
    renderTable();
    renderCards();
    renderPagination();
    setText(SELECTORS.total, utils.formatNumber(state.pagination.total));
    setHidden(SELECTORS.empty, state.logs.length > 0);
    permissions.apply(document);
}

function renderTable() {
    document.querySelectorAll(SELECTORS.tableBody).forEach((body) => {
        const fragment = document.createDocumentFragment();
        state.logs.forEach((log) => fragment.appendChild(createTableRow(log)));
        body.replaceChildren(fragment);
    });
}

function createTableRow(log) {
    const row = document.createElement("tr");
    row.dataset.activityLogId = String(log.id);

    const dateCell = createCell(utils.formatDateTime(log.createdAt, {
        showSeconds: true
    }));
    const userCell = createCell(log.actorName);
    const actionCell = document.createElement("td");
    actionCell.appendChild(createActionBadge(log.action));
    const descriptionCell = createCell(log.description);
    const ipCell = createCell(log.ipAddress || "-");
    const statusCell = document.createElement("td");
    statusCell.appendChild(createStatusBadge(log.status));
    const controlCell = document.createElement("td");
    controlCell.appendChild(createActionControls(log));

    row.append(
        dateCell,
        userCell,
        actionCell,
        descriptionCell,
        ipCell,
        statusCell,
        controlCell
    );
    return row;
}

function renderCards() {
    document.querySelectorAll(SELECTORS.cardList).forEach((container) => {
        const fragment = document.createDocumentFragment();

        state.logs.forEach((log) => {
            const card = document.createElement("article");
            card.className = "cp-activity-log-card";

            const header = document.createElement("div");
            header.className = "cp-activity-log-card__header";
            const actor = document.createElement("strong");
            actor.textContent = log.actorName;
            header.append(actor, createActionBadge(log.action));

            const description = document.createElement("p");
            description.textContent = log.description;
            const meta = document.createElement("small");
            meta.textContent = `${utils.formatDateTime(log.createdAt)} · ${log.ipAddress || "ไม่ระบุ IP"}`;

            card.append(
                header,
                description,
                meta,
                createStatusBadge(log.status),
                createActionControls(log)
            );
            fragment.appendChild(card);
        });

        container.replaceChildren(fragment);
    });
}

function createActionControls(log) {
    const actions = document.createElement("div");
    actions.className = "cp-contact-card__actions";

    const detailButton = document.createElement("button");
    detailButton.type = "button";
    detailButton.className = "cp-button cp-button--secondary cp-button--small";
    detailButton.textContent = "รายละเอียด";
    detailButton.dataset.activityLogDetail = String(log.id);
    detailButton.dataset.permission = PERMISSIONS.ACTIVITY_LOG.VIEW;

    const deleteButton = document.createElement("button");
    deleteButton.type = "button";
    deleteButton.className = "cp-button cp-button--danger cp-button--small";
    deleteButton.textContent = "ลบ";
    deleteButton.dataset.activityLogDelete = String(log.id);
    deleteButton.dataset.permission = PERMISSIONS.ACTIVITY_LOG.DELETE;

    actions.append(detailButton, deleteButton);
    return actions;
}

function createDetailContent(log) {
    const container = document.createElement("div");
    container.className = "cp-admin-form-grid";

    const fields = [
        ["วันและเวลา", utils.formatDateTime(log.createdAt, { showSeconds: true })],
        ["ผู้ดำเนินการ", log.actorName],
        ["ชื่อผู้ใช้", log.username || "-"],
        ["กิจกรรม", getActionLabel(log.action)],
        ["โมดูล", log.module || "-"],
        ["สถานะ", log.status],
        ["IP Address", log.ipAddress || "-"],
        ["User Agent", log.userAgent || "-"],
        ["รายละเอียด", log.description]
    ];

    fields.forEach(([labelText, value]) => {
        const group = document.createElement("div");
        group.className = "cp-form-group";
        const label = document.createElement("strong");
        label.textContent = labelText;
        const content = document.createElement("p");
        content.textContent = String(value);
        group.append(label, content);
        container.appendChild(group);
    });

    if (!utils.isEmpty(log.metadata)) {
        const metadataGroup = document.createElement("div");
        metadataGroup.className = "cp-form-group cp-admin-form-grid__full";
        const label = document.createElement("strong");
        label.textContent = "Metadata";
        const pre = document.createElement("pre");
        pre.className = "cp-code-block";
        pre.textContent = JSON.stringify(log.metadata, null, 2);
        metadataGroup.append(label, pre);
        container.appendChild(metadataGroup);
    }

    return container;
}

function createCell(value) {
    const cell = document.createElement("td");
    cell.textContent = String(value);
    return cell;
}

function createActionBadge(action) {
    const badge = document.createElement("span");
    badge.className = `cp-badge cp-badge--${getActionVariant(action)}`;
    badge.textContent = getActionLabel(action);
    return badge;
}

function createStatusBadge(status) {
    const success = status === "success";
    const badge = document.createElement("span");
    badge.className = `cp-badge cp-badge--${success ? "success" : "danger"}`;
    badge.textContent = success ? "สำเร็จ" : "ไม่สำเร็จ";
    return badge;
}

function getActionLabel(action) {
    const labels = {
        login: "เข้าสู่ระบบ",
        logout: "ออกจากระบบ",
        create: "เพิ่มข้อมูล",
        update: "แก้ไขข้อมูล",
        delete: "ลบข้อมูล",
        restore: "กู้คืนข้อมูล",
        import: "นำเข้าข้อมูล",
        export: "ส่งออกข้อมูล",
        view: "ดูข้อมูล"
    };

    return labels[action] || utils.capitalize(action || "unknown");
}

function getActionVariant(action) {
    const variants = {
        create: "success",
        update: "warning",
        delete: "danger",
        restore: "info",
        login: "success",
        logout: "neutral",
        import: "info",
        export: "info",
        view: "neutral"
    };

    return variants[action] || "neutral";
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

function populateSelect(selector, options, placeholder, selectedValue) {
    document.querySelectorAll(selector).forEach((select) => {
        const fragment = document.createDocumentFragment();
        const initial = document.createElement("option");
        initial.value = "";
        initial.textContent = placeholder;
        fragment.appendChild(initial);

        options.forEach((item) => {
            const option = document.createElement("option");
            option.value = String(item.value);
            option.textContent = item.label;
            fragment.appendChild(option);
        });

        select.replaceChildren(fragment);
        select.value = String(selectedValue || "");
    });
}

function validateDateRange() {
    if (!state.query.dateFrom || !state.query.dateTo) return true;

    const from = new Date(`${state.query.dateFrom}T00:00:00`);
    const to = new Date(`${state.query.dateTo}T23:59:59`);

    if (from.getTime() > to.getTime()) {
        throw new Error("วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด");
    }

    return true;
}

function restoreQueryFromUrl() {
    const params = utils.getQueryParams();
    state.query.search = String(params.search || "");
    state.query.action = String(params.action || "");
    state.query.userId = String(params.user_id || "");
    state.query.status = String(params.status || "");
    state.query.dateFrom = normalizeDateInput(params.date_from);
    state.query.dateTo = normalizeDateInput(params.date_to);
    state.query.page = Math.max(1, utils.toInteger(params.page, 1));
    state.query.limit = utils.clamp(
        utils.toInteger(params.limit, CONFIG.pageSize),
        10,
        100
    );
}

function syncControls() {
    setControlValue(SELECTORS.search, state.query.search);
    setControlValue(SELECTORS.actionFilter, state.query.action);
    setControlValue(SELECTORS.userFilter, state.query.userId);
    setControlValue(SELECTORS.statusFilter, state.query.status);
    setControlValue(SELECTORS.dateFrom, state.query.dateFrom);
    setControlValue(SELECTORS.dateTo, state.query.dateTo);
    setControlValue(SELECTORS.pageSize, state.query.limit);
}

function syncQueryToUrl() {
    utils.updateQueryParams({
        search: state.query.search,
        action: state.query.action,
        user_id: state.query.userId,
        status: state.query.status,
        date_from: state.query.dateFrom,
        date_to: state.query.dateTo,
        page: state.query.page > 1 ? state.query.page : null,
        limit: state.query.limit !== CONFIG.pageSize ? state.query.limit : null
    }, { replace: true });
}

function resetFilters() {
    state.query = {
        search: "",
        action: "",
        userId: "",
        status: "",
        dateFrom: "",
        dateTo: "",
        page: 1,
        limit: CONFIG.pageSize
    };
    syncControls();
    loadActivityLogs().catch(() => {});
}

function normalizeLog(value) {
    const id = Number(value?.id || value?.activity_id || 0);
    if (!Number.isInteger(id) || id < 1) return null;

    return Object.freeze({
        id,
        userId: value.user_id || value.userId || null,
        username: String(value.username || ""),
        actorName: String(
            value.actor_name ||
            value.display_name ||
            value.actorName ||
            value.username ||
            "ระบบ"
        ),
        action: String(value.action || value.event_type || "unknown").toLowerCase(),
        module: String(value.module || value.entity_type || ""),
        description: String(
            value.description || value.message || "ไม่มีรายละเอียด"
        ),
        ipAddress: String(value.ip_address || value.ipAddress || ""),
        userAgent: String(value.user_agent || value.userAgent || ""),
        status: String(value.status || "success").toLowerCase(),
        metadata: normalizeMetadata(value.metadata || value.details),
        createdAt: value.created_at || value.createdAt || null
    });
}

function normalizeMetadata(value) {
    if (!value) return null;
    if (typeof value === "object") return value;

    try {
        return JSON.parse(value);
    } catch {
        return { value: String(value) };
    }
}

function normalizeActionOption(value) {
    if (typeof value === "string") {
        return { value, label: getActionLabel(value) };
    }

    const action = value?.value || value?.action || value?.name;
    if (!action) return null;

    return {
        value: String(action),
        label: String(value.label || value.display_name || getActionLabel(action))
    };
}

function normalizeUserOption(value) {
    const id = value?.id || value?.user_id;
    if (id === undefined || id === null || id === "") return null;

    return {
        value: id,
        label: String(
            value.display_name || value.displayName || value.username || "ไม่ระบุชื่อ"
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

function normalizeDateInput(value) {
    const text = String(value || "");
    return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : "";
}

function extractItems(value, key) {
    if (Array.isArray(value)) return value;
    return value?.[key] || value?.items || [];
}

function assertValidId(value) {
    if (!Number.isInteger(value) || value < 1) {
        throw new TypeError("Activity Log ID ไม่ถูกต้อง");
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

async function runWithOptionalButtonLoading(button, task, options) {
    if (button instanceof HTMLElement) {
        return components.withButtonLoading(button, task, options);
    }
    return task();
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
    console.error("[ConnectPro Admin Activity Log]", error);
    components.toast.error(error?.message || fallback);
}

document.addEventListener("DOMContentLoaded", initializeActivityLog, {
    once: true
});

export default Object.freeze({
    init: initializeActivityLog,
    load: loadActivityLogs,
    detail: showLogDetail,
    remove: deleteActivityLog,
    export: exportActivityLogs
});
