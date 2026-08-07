/**
 * ConnectPro Admin Import / Export
 * File: frontend/assets/js/admin/import-export.js
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
        preview: "admin/import-export/preview.php",
        importContacts: "admin/import-export/import.php",
        exportContacts: "admin/import-export/export.php",
        downloadTemplate: "admin/import-export/template.php",
        importHistory: "admin/import-export/history.php",
        downloadErrors: "admin/import-export/errors.php"
    }),
    allowedExtensions: ["csv", "xlsx"],
    allowedMimeTypes: [
        "text/csv",
        "application/csv",
        "application/vnd.ms-excel",
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    ],
    maxFileSize: 10 * 1024 * 1024,
    previewLimit: 100,
    historyLimit: 20,
    exportFileName: "connectpro-contacts.xlsx",
    templateFileName: "connectpro-import-template.xlsx"
});

const SELECTORS = Object.freeze({
    page: "[data-admin-import-export]",
    dropZone: "[data-import-drop-zone]",
    fileInput: "[data-import-file]",
    selectFileButton: "[data-import-select-file]",
    selectedFile: "[data-import-selected-file]",
    selectedFileName: "[data-import-file-name]",
    selectedFileSize: "[data-import-file-size]",
    removeFileButton: "[data-import-remove-file]",
    previewButton: "[data-import-preview]",
    importButton: "[data-import-submit]",
    templateButton: "[data-import-template]",
    exportButton: "[data-export-submit]",
    exportFormat: "[data-export-format]",
    exportStatus: "[data-export-status]",
    exportDepartment: "[data-export-department]",
    previewSection: "[data-import-preview-section]",
    previewBody: "[data-import-preview-body]",
    previewSummary: "[data-import-preview-summary]",
    validCount: "[data-import-valid-count]",
    invalidCount: "[data-import-invalid-count]",
    resultSection: "[data-import-result]",
    importedCount: "[data-imported-count]",
    skippedCount: "[data-import-skipped-count]",
    failedCount: "[data-import-failed-count]",
    errorList: "[data-import-error-list]",
    downloadErrorsButton: "[data-import-download-errors]",
    historyBody: "[data-import-history-body]",
    historyEmpty: "[data-import-history-empty]",
    progress: "[data-import-progress]",
    progressBar: "[data-import-progress-bar]",
    progressText: "[data-import-progress-text]",
    error: "[data-import-export-error]",
    logoutButton: "[data-logout]"
});

const state = {
    initialized: false,
    file: null,
    previewToken: null,
    previewRows: [],
    previewSummary: { total: 0, valid: 0, invalid: 0 },
    importResult: null,
    history: [],
    busy: false
};

async function initializeImportExport() {
    if (state.initialized) return;
    state.initialized = true;

    components.init();
    auth.init();

    try {
        const user = await auth.requireAuth({ roles: ["admin"] });
        if (!user) return;

        permissions.init();
        auth.hydrateUserElements(document, user);
        bindEvents();
        applyPermissionState();

        await Promise.allSettled([
            notifications.init({ showToastForNew: true }),
            loadImportHistory()
        ]);
    } catch (error) {
        handleError(error, "ไม่สามารถเริ่มต้นหน้านำเข้าและส่งออกข้อมูลได้");
    }
}

function bindEvents() {
    const input = document.querySelector(SELECTORS.fileInput);
    const dropZone = document.querySelector(SELECTORS.dropZone);

    document.querySelectorAll(SELECTORS.selectFileButton).forEach((button) => {
        button.addEventListener("click", () => input?.click());
    });

    input?.addEventListener("change", (event) => {
        selectFile(event.target.files?.[0] || null);
    });

    if (dropZone) {
        ["dragenter", "dragover"].forEach((eventName) => {
            dropZone.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropZone.classList.add("is-dragging");
            });
        });

        ["dragleave", "drop"].forEach((eventName) => {
            dropZone.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropZone.classList.remove("is-dragging");
            });
        });

        dropZone.addEventListener("drop", (event) => {
            selectFile(event.dataTransfer?.files?.[0] || null);
        });

        dropZone.addEventListener("keydown", (event) => {
            if (["Enter", " "].includes(event.key)) {
                event.preventDefault();
                input?.click();
            }
        });
    }

    document.querySelectorAll(SELECTORS.removeFileButton).forEach((button) => {
        button.addEventListener("click", clearSelectedFile);
    });

    document.querySelectorAll(SELECTORS.previewButton).forEach((button) => {
        button.addEventListener("click", () => previewImport(button));
    });

    document.querySelectorAll(SELECTORS.importButton).forEach((button) => {
        button.addEventListener("click", () => executeImport(button));
    });

    document.querySelectorAll(SELECTORS.templateButton).forEach((button) => {
        button.addEventListener("click", () => downloadTemplate(button));
    });

    document.querySelectorAll(SELECTORS.exportButton).forEach((button) => {
        button.addEventListener("click", () => exportContacts(button));
    });

    document.querySelectorAll(SELECTORS.downloadErrorsButton).forEach((button) => {
        button.addEventListener("click", () => downloadImportErrors(button));
    });

    document.querySelectorAll(SELECTORS.logoutButton).forEach((button) => {
        button.addEventListener("click", handleLogout);
    });
}

function selectFile(file) {
    clearError();
    resetPreview();
    resetResult();

    if (!file) {
        clearSelectedFile();
        return;
    }

    const validation = validateFile(file);
    if (!validation.valid) {
        clearSelectedFile();
        showError(validation.message);
        components.toast.error(validation.message);
        return;
    }

    state.file = file;
    setText(SELECTORS.selectedFileName, file.name);
    setText(SELECTORS.selectedFileSize, utils.formatFileSize(file.size));
    setHidden(SELECTORS.selectedFile, false);
    updateActionState();
}

function validateFile(file) {
    const extension = file.name.split(".").pop()?.toLowerCase() || "";

    if (!CONFIG.allowedExtensions.includes(extension)) {
        return {
            valid: false,
            message: "รองรับเฉพาะไฟล์ CSV และ XLSX"
        };
    }

    if (file.size <= 0) {
        return { valid: false, message: "ไฟล์ที่เลือกไม่มีข้อมูล" };
    }

    if (file.size > CONFIG.maxFileSize) {
        return {
            valid: false,
            message: `ขนาดไฟล์ต้องไม่เกิน ${utils.formatFileSize(CONFIG.maxFileSize)}`
        };
    }

    if (file.type && !CONFIG.allowedMimeTypes.includes(file.type)) {
        return { valid: false, message: "ชนิดไฟล์ไม่ถูกต้อง" };
    }

    return { valid: true, message: "" };
}

function clearSelectedFile() {
    state.file = null;
    state.previewToken = null;

    const input = document.querySelector(SELECTORS.fileInput);
    if (input) input.value = "";

    setText(SELECTORS.selectedFileName, "");
    setText(SELECTORS.selectedFileSize, "");
    setHidden(SELECTORS.selectedFile, true);
    resetPreview();
    resetResult();
    updateActionState();
}

export async function previewImport(button = null) {
    permissions.authorize(PERMISSIONS.CONTACTS.IMPORT);

    if (!state.file) {
        components.toast.warning("กรุณาเลือกไฟล์ก่อนตรวจสอบ");
        return;
    }

    setBusy(true);
    clearError();

    try {
        await runWithOptionalButtonLoading(button, async () => {
            const formData = new FormData();
            formData.append("file", state.file);
            formData.append("preview_limit", String(CONFIG.previewLimit));

            const response = await api.upload(CONFIG.endpoints.preview, formData, {
                requestKey: "contacts-import-preview",
                cancelPrevious: true,
                timeout: 60000
            });

            state.previewToken = String(response?.preview_token || response?.previewToken || "");
            const rows = response?.rows || response?.preview || [];
            state.previewRows = Array.isArray(rows)
                ? rows.map(normalizePreviewRow).filter(Boolean)
                : [];
            state.previewSummary = normalizePreviewSummary(
                response?.summary,
                state.previewRows
            );

            renderPreview();
            resetResult();

            if (state.previewSummary.invalid > 0) {
                components.toast.warning(
                    `พบข้อมูลผิดพลาด ${state.previewSummary.invalid} รายการ`
                );
            } else {
                components.toast.success("ตรวจสอบไฟล์เรียบร้อยแล้ว");
            }
        }, { text: "กำลังตรวจสอบ..." });
    } catch (error) {
        showError(error.message || "ตรวจสอบไฟล์ไม่สำเร็จ");
        handleError(error, "ตรวจสอบไฟล์ไม่สำเร็จ");
    } finally {
        setBusy(false);
    }
}

export async function executeImport(button = null) {
    permissions.authorize(PERMISSIONS.CONTACTS.IMPORT);

    if (!state.file || !state.previewToken) {
        components.toast.warning("กรุณาตรวจสอบไฟล์ก่อนนำเข้า");
        return;
    }

    if (state.previewSummary.valid < 1) {
        components.toast.warning("ไม่มีข้อมูลที่ถูกต้องสำหรับนำเข้า");
        return;
    }

    const confirmed = await components.confirm({
        title: "ยืนยันการนำเข้าข้อมูล",
        message: `พร้อมนำเข้าข้อมูลที่ถูกต้อง ${state.previewSummary.valid} รายการ`,
        confirmText: "เริ่มนำเข้า",
        cancelText: "ยกเลิก"
    });

    if (!confirmed) return;

    setBusy(true);
    setProgress(true, 15, "กำลังเตรียมข้อมูล...");

    try {
        await runWithOptionalButtonLoading(button, async () => {
            const formData = new FormData();
            formData.append("file", state.file);
            formData.append("preview_token", state.previewToken);

            window.setTimeout(() => {
                if (state.busy) setProgress(true, 55, "กำลังนำเข้าข้อมูล...");
            }, 500);

            const response = await api.upload(
                CONFIG.endpoints.importContacts,
                formData,
                {
                    requestKey: "contacts-import-execute",
                    cancelPrevious: false,
                    timeout: 120000
                }
            );

            state.importResult = normalizeImportResult(response);
            setProgress(true, 100, "นำเข้าข้อมูลเสร็จสิ้น");
            renderImportResult();
            await loadImportHistory();
            components.toast.success(
                `นำเข้าสำเร็จ ${state.importResult.imported} รายการ`
            );
        }, { text: "กำลังนำเข้า..." });
    } catch (error) {
        setProgress(false);
        showError(error.message || "นำเข้าข้อมูลไม่สำเร็จ");
        handleError(error, "นำเข้าข้อมูลไม่สำเร็จ");
    } finally {
        state.busy = false;
        window.setTimeout(() => setProgress(false), 800);
        updateActionState();
    }
}

export async function exportContacts(button = null) {
    permissions.authorize(PERMISSIONS.CONTACTS.EXPORT);

    const format = getSelectValue(SELECTORS.exportFormat) || "xlsx";
    const status = getSelectValue(SELECTORS.exportStatus);
    const departmentId = getSelectValue(SELECTORS.exportDepartment);

    if (!["xlsx", "csv"].includes(format)) {
        components.toast.error("รูปแบบไฟล์ส่งออกไม่ถูกต้อง");
        return;
    }

    setBusy(true);

    try {
        await runWithOptionalButtonLoading(button, async () => {
            const blob = await api.download(CONFIG.endpoints.exportContacts, {
                query: {
                    format,
                    status,
                    department_id: departmentId
                },
                requestKey: "contacts-export",
                timeout: 120000
            });

            const fileName = format === "csv"
                ? CONFIG.exportFileName.replace(/\.xlsx$/i, ".csv")
                : CONFIG.exportFileName;

            utils.downloadBlob(blob, fileName);
            components.toast.success("ส่งออกข้อมูลเรียบร้อยแล้ว");
        }, { text: "กำลังส่งออก..." });
    } catch (error) {
        handleError(error, "ส่งออกข้อมูลไม่สำเร็จ");
    } finally {
        setBusy(false);
    }
}

export async function downloadTemplate(button = null) {
    permissions.authorize(PERMISSIONS.CONTACTS.IMPORT);
    setBusy(true);

    try {
        await runWithOptionalButtonLoading(button, async () => {
            const blob = await api.download(CONFIG.endpoints.downloadTemplate, {
                requestKey: "contacts-import-template"
            });
            utils.downloadBlob(blob, CONFIG.templateFileName);
        }, { text: "กำลังดาวน์โหลด..." });
    } catch (error) {
        handleError(error, "ดาวน์โหลด Template ไม่สำเร็จ");
    } finally {
        setBusy(false);
    }
}

export async function loadImportHistory() {
    if (!permissions.has(PERMISSIONS.CONTACTS.IMPORT)) {
        state.history = [];
        renderHistory();
        return [];
    }

    try {
        const response = await api.get(CONFIG.endpoints.importHistory, {
            query: { limit: CONFIG.historyLimit },
            requestKey: "contacts-import-history",
            cancelPrevious: true
        });

        const items = Array.isArray(response)
            ? response
            : response?.history || response?.items || [];

        state.history = items.map(normalizeHistoryItem).filter(Boolean);
        renderHistory();
        return state.history;
    } catch (error) {
        console.error("[ConnectPro Import History]", error);
        return [];
    }
}

async function downloadImportErrors(button = null) {
    const batchId = state.importResult?.batchId;
    if (!batchId) return;

    try {
        await runWithOptionalButtonLoading(button, async () => {
            const blob = await api.download(CONFIG.endpoints.downloadErrors, {
                query: { batch_id: batchId },
                requestKey: `import-errors-${batchId}`
            });
            utils.downloadBlob(blob, `connectpro-import-errors-${batchId}.csv`);
        }, { text: "กำลังดาวน์โหลด..." });
    } catch (error) {
        handleError(error, "ดาวน์โหลดรายการข้อผิดพลาดไม่สำเร็จ");
    }
}

function renderPreview() {
    document.querySelectorAll(SELECTORS.previewBody).forEach((body) => {
        const fragment = document.createDocumentFragment();
        state.previewRows.forEach((row) => fragment.appendChild(createPreviewRow(row)));
        body.replaceChildren(fragment);
    });

    setText(
        SELECTORS.previewSummary,
        `ทั้งหมด ${utils.formatNumber(state.previewSummary.total)} รายการ`
    );
    setText(SELECTORS.validCount, utils.formatNumber(state.previewSummary.valid));
    setText(SELECTORS.invalidCount, utils.formatNumber(state.previewSummary.invalid));
    setHidden(SELECTORS.previewSection, false);
    updateActionState();
}

function createPreviewRow(row) {
    const tr = document.createElement("tr");
    tr.classList.toggle("is-invalid", !row.valid);

    [
        row.rowNumber,
        row.employeeCode || "-",
        row.displayName || "-",
        row.extensionNumber || "-",
        row.department || "-",
        row.location || "-"
    ].forEach((value) => {
        const cell = document.createElement("td");
        cell.textContent = String(value);
        tr.appendChild(cell);
    });

    const statusCell = document.createElement("td");
    const status = document.createElement("span");
    status.className = `cp-badge cp-badge--${row.valid ? "success" : "danger"}`;
    status.textContent = row.valid ? "พร้อมนำเข้า" : row.errors.join(", ");
    statusCell.appendChild(status);
    tr.appendChild(statusCell);
    return tr;
}

function renderImportResult() {
    if (!state.importResult) return;

    setText(SELECTORS.importedCount, utils.formatNumber(state.importResult.imported));
    setText(SELECTORS.skippedCount, utils.formatNumber(state.importResult.skipped));
    setText(SELECTORS.failedCount, utils.formatNumber(state.importResult.failed));

    document.querySelectorAll(SELECTORS.errorList).forEach((container) => {
        const fragment = document.createDocumentFragment();
        state.importResult.errors.slice(0, 50).forEach((error) => {
            const item = document.createElement("li");
            item.textContent = `แถว ${error.row}: ${error.message}`;
            fragment.appendChild(item);
        });
        container.replaceChildren(fragment);
        container.hidden = state.importResult.errors.length === 0;
    });

    setHidden(SELECTORS.downloadErrorsButton, state.importResult.errors.length === 0);
    setHidden(SELECTORS.resultSection, false);
}

function renderHistory() {
    document.querySelectorAll(SELECTORS.historyBody).forEach((body) => {
        const fragment = document.createDocumentFragment();

        state.history.forEach((item) => {
            const row = document.createElement("tr");
            [
                item.fileName,
                utils.formatNumber(item.totalRows),
                utils.formatNumber(item.imported),
                utils.formatNumber(item.failed),
                item.importedBy,
                utils.formatDateTime(item.createdAt)
            ].forEach((value) => {
                const cell = document.createElement("td");
                cell.textContent = String(value);
                row.appendChild(cell);
            });
            body.appendChild(row);
            fragment.appendChild(row);
        });

        body.replaceChildren(fragment);
    });

    setHidden(SELECTORS.historyEmpty, state.history.length > 0);
}

function normalizePreviewRow(value) {
    if (!value || typeof value !== "object") return null;

    return Object.freeze({
        rowNumber: Math.max(1, utils.toInteger(value.row ?? value.row_number, 1)),
        employeeCode: String(value.employee_code || value.employeeCode || ""),
        displayName: String(value.display_name || value.displayName || ""),
        extensionNumber: String(value.extension_number || value.extensionNumber || ""),
        department: String(value.department || value.department_name || ""),
        location: String(value.location || value.location_name || ""),
        valid: utils.toBoolean(value.valid ?? value.is_valid, false),
        errors: Array.isArray(value.errors)
            ? value.errors.map(String)
            : value.error
                ? [String(value.error)]
                : []
    });
}

function normalizePreviewSummary(summary = {}, rows = []) {
    const derivedValid = rows.filter((row) => row.valid).length;
    const derivedInvalid = rows.length - derivedValid;

    return Object.freeze({
        total: Math.max(0, utils.toInteger(summary.total, rows.length)),
        valid: Math.max(0, utils.toInteger(summary.valid, derivedValid)),
        invalid: Math.max(0, utils.toInteger(summary.invalid, derivedInvalid))
    });
}

function normalizeImportResult(value = {}) {
    const errors = Array.isArray(value.errors) ? value.errors : [];

    return Object.freeze({
        batchId: String(value.batch_id || value.batchId || ""),
        imported: Math.max(0, utils.toInteger(value.imported, 0)),
        skipped: Math.max(0, utils.toInteger(value.skipped, 0)),
        failed: Math.max(0, utils.toInteger(value.failed, errors.length)),
        errors: errors.map((error) => ({
            row: Math.max(1, utils.toInteger(error.row, 1)),
            message: String(error.message || error.error || "ข้อมูลไม่ถูกต้อง")
        }))
    });
}

function normalizeHistoryItem(value) {
    if (!value || typeof value !== "object") return null;
    const id = Number(value.id || value.batch_id || 0);
    if (!Number.isFinite(id) || id < 1) return null;

    return Object.freeze({
        id,
        fileName: String(value.file_name || value.fileName || "-"),
        totalRows: Math.max(0, utils.toInteger(value.total_rows ?? value.totalRows, 0)),
        imported: Math.max(0, utils.toInteger(value.imported, 0)),
        failed: Math.max(0, utils.toInteger(value.failed, 0)),
        importedBy: String(value.imported_by || value.importedBy || "ระบบ"),
        createdAt: value.created_at || value.createdAt || null
    });
}

function applyPermissionState() {
    permissions.apply(document);
    updateActionState();
}

function updateActionState() {
    const canImport = permissions.has(PERMISSIONS.CONTACTS.IMPORT);

    document.querySelectorAll(SELECTORS.previewButton).forEach((button) => {
        button.disabled = state.busy || !state.file || !canImport;
    });

    document.querySelectorAll(SELECTORS.importButton).forEach((button) => {
        button.disabled =
            state.busy ||
            !state.previewToken ||
            state.previewSummary.valid < 1 ||
            !canImport;
    });
}

function resetPreview() {
    state.previewToken = null;
    state.previewRows = [];
    state.previewSummary = { total: 0, valid: 0, invalid: 0 };
    document.querySelectorAll(SELECTORS.previewBody).forEach((body) => {
        body.replaceChildren();
    });
    setHidden(SELECTORS.previewSection, true);
}

function resetResult() {
    state.importResult = null;
    setHidden(SELECTORS.resultSection, true);
    setHidden(SELECTORS.downloadErrorsButton, true);
}

function setBusy(busy) {
    state.busy = busy;
    document.querySelectorAll(SELECTORS.page).forEach((element) => {
        element.setAttribute("aria-busy", String(busy));
    });
    updateActionState();
}

function setProgress(visible, percentage = 0, text = "") {
    const value = utils.clamp(percentage, 0, 100);
    setHidden(SELECTORS.progress, !visible);
    setText(SELECTORS.progressText, text);

    document.querySelectorAll(SELECTORS.progressBar).forEach((bar) => {
        bar.style.width = `${value}%`;
        bar.setAttribute("aria-valuenow", String(value));
    });
}

async function runWithOptionalButtonLoading(button, task, options) {
    if (button instanceof HTMLElement) {
        return components.withButtonLoading(button, task, options);
    }
    return task();
}

function getSelectValue(selector) {
    return document.querySelector(selector)?.value || "";
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

function clearError() {
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
    console.error("[ConnectPro Admin Import Export]", error);
    components.toast.error(error?.message || fallback);
}

document.addEventListener("DOMContentLoaded", initializeImportExport, {
    once: true
});

export default Object.freeze({
    init: initializeImportExport,
    preview: previewImport,
    import: executeImport,
    export: exportContacts,
    downloadTemplate,
    loadHistory: loadImportHistory,
    clearFile: clearSelectedFile
});
