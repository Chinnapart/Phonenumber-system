/**
 * ConnectPro Admin Settings
 * File: frontend/assets/js/admin/settings.js
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
        get: "admin/settings/get.php",
        updateGeneral: "admin/settings/update-general.php",
        updateSecurity: "admin/settings/update-security.php",
        updateAppearance: "admin/settings/update-appearance.php",
        backup: "admin/settings/backup.php",
        restore: "admin/settings/restore.php",
        backupHistory: "admin/settings/backup-history.php",
        downloadBackup: "admin/settings/download-backup.php"
    }),
    restoreExtensions: ["zip", "sql"],
    restoreMimeTypes: [
        "application/zip",
        "application/x-zip-compressed",
        "application/sql",
        "text/plain",
        "application/octet-stream"
    ],
    maximumRestoreSize: 100 * 1024 * 1024,
    backupHistoryLimit: 20
});

const SELECTORS = Object.freeze({
    page: "[data-admin-settings]",
    generalForm: "[data-settings-general-form]",
    securityForm: "[data-settings-security-form]",
    appearanceForm: "[data-settings-appearance-form]",
    generalSave: "[data-settings-save-general]",
    securitySave: "[data-settings-save-security]",
    appearanceSave: "[data-settings-save-appearance]",
    resetForm: "[data-settings-reset]",
    backupButton: "[data-settings-backup]",
    restoreInput: "[data-settings-restore-file]",
    restoreSelectButton: "[data-settings-select-restore]",
    restoreButton: "[data-settings-restore]",
    restoreFileInfo: "[data-settings-restore-file-info]",
    restoreFileName: "[data-settings-restore-file-name]",
    restoreFileSize: "[data-settings-restore-file-size]",
    removeRestoreFile: "[data-settings-remove-restore-file]",
    backupHistoryBody: "[data-settings-backup-history-body]",
    backupHistoryEmpty: "[data-settings-backup-history-empty]",
    loading: "[data-settings-loading]",
    error: "[data-settings-error]",
    logoutButton: "[data-logout]"
});

const state = {
    initialized: false,
    loading: false,
    settings: {
        general: {},
        security: {},
        appearance: {}
    },
    restoreFile: null,
    backupHistory: []
};

async function initializeSettings() {
    if (state.initialized) return;
    state.initialized = true;

    components.init();
    auth.init();

    try {
        const user = await auth.requireAuth({ roles: ["admin"] });
        if (!user) return;

        permissions.init();
        permissions.authorize(PERMISSIONS.SETTINGS.VIEW);
        auth.hydrateUserElements(document, user);
        bindEvents();
        applyPermissionState();

        await Promise.allSettled([
            notifications.init({ showToastForNew: true }),
            loadSettings(),
            loadBackupHistory()
        ]);
    } catch (error) {
        handleError(error, "ไม่สามารถเริ่มต้นหน้าตั้งค่าระบบได้");
    }
}

export async function loadSettings(options = {}) {
    if (state.loading) return;

    state.loading = true;
    setLoading(true);
    hideError();

    try {
        const response = await api.get(CONFIG.endpoints.get, {
            requestKey: "admin-settings-get",
            cancelPrevious: true
        });

        state.settings = normalizeSettings(response?.settings || response);
        populateForms();

        if (options.showSuccess) {
            components.toast.success("โหลดการตั้งค่าล่าสุดแล้ว", { duration: 2000 });
        }
    } catch (error) {
        if (!(error instanceof ApiError && error.isCancelled)) {
            showError(error.message || "โหลดการตั้งค่าไม่สำเร็จ");
            if (!options.silent) handleError(error, "โหลดการตั้งค่าไม่สำเร็จ");
        }
    } finally {
        state.loading = false;
        setLoading(false);
    }
}

function bindEvents() {
    bindFormSubmit(
        SELECTORS.generalForm,
        SELECTORS.generalSave,
        saveGeneralSettings
    );
    bindFormSubmit(
        SELECTORS.securityForm,
        SELECTORS.securitySave,
        saveSecuritySettings
    );
    bindFormSubmit(
        SELECTORS.appearanceForm,
        SELECTORS.appearanceSave,
        saveAppearanceSettings
    );

    document.querySelectorAll(SELECTORS.resetForm).forEach((button) => {
        button.addEventListener("click", () => {
            populateForms();
            components.toast.info("คืนค่าฟอร์มเป็นข้อมูลล่าสุดแล้ว", {
                duration: 2000
            });
        });
    });

    document.querySelectorAll(SELECTORS.backupButton).forEach((button) => {
        button.addEventListener("click", () => createBackup(button));
    });

    const restoreInput = document.querySelector(SELECTORS.restoreInput);

    document.querySelectorAll(SELECTORS.restoreSelectButton).forEach((button) => {
        button.addEventListener("click", () => restoreInput?.click());
    });

    restoreInput?.addEventListener("change", (event) => {
        selectRestoreFile(event.target.files?.[0] || null);
    });

    document.querySelectorAll(SELECTORS.removeRestoreFile).forEach((button) => {
        button.addEventListener("click", clearRestoreFile);
    });

    document.querySelectorAll(SELECTORS.restoreButton).forEach((button) => {
        button.addEventListener("click", () => restoreBackup(button));
    });

    document.querySelectorAll(SELECTORS.logoutButton).forEach((button) => {
        button.addEventListener("click", handleLogout);
    });

    document.addEventListener("click", handleDelegatedClick);
}

function bindFormSubmit(formSelector, buttonSelector, handler) {
    const form = document.querySelector(formSelector);

    form?.addEventListener("submit", (event) => {
        event.preventDefault();
        const button = form.querySelector("[type=\"submit\"]") ||
            document.querySelector(buttonSelector);
        handler(form, button).catch(() => {});
    });

    document.querySelectorAll(buttonSelector).forEach((button) => {
        if (button.closest("form") === form) return;
        button.addEventListener("click", () => handler(form, button).catch(() => {}));
    });
}

async function handleDelegatedClick(event) {
    const downloadButton = event.target.closest("[data-backup-download]");

    if (!downloadButton) return;

    try {
        await downloadBackup(
            downloadButton.dataset.backupDownload,
            downloadButton
        );
    } catch (error) {
        handleError(error, "ดาวน์โหลด Backup ไม่สำเร็จ");
    }
}

export async function saveGeneralSettings(form, button = null) {
    permissions.authorize(PERMISSIONS.SETTINGS.UPDATE_GENERAL);
    assertForm(form);
    utils.clearFormErrors(form);

    if (!form.reportValidity()) return false;

    const values = utils.serializeForm(form);
    const errors = validateGeneralSettings(values);

    if (Object.keys(errors).length > 0) {
        utils.applyFormErrors(form, errors);
        return false;
    }

    const payload = {
        application_name: utils.normalizeText(values.application_name),
        organization_name: utils.normalizeText(values.organization_name),
        support_email: utils.normalizeText(values.support_email),
        support_phone: utils.normalizeText(values.support_phone),
        default_language: values.default_language || "th",
        time_zone: values.time_zone || "Asia/Bangkok",
        records_per_page: utils.clamp(
            utils.toInteger(values.records_per_page, 20),
            10,
            100
        ),
        maintenance_mode: utils.toBoolean(values.maintenance_mode)
    };

    return saveSettingsSection({
        form,
        button,
        endpoint: CONFIG.endpoints.updateGeneral,
        payload,
        permission: PERMISSIONS.SETTINGS.UPDATE_GENERAL,
        stateKey: "general",
        successMessage: "บันทึกการตั้งค่าทั่วไปแล้ว"
    });
}

export async function saveSecuritySettings(form, button = null) {
    permissions.authorize(PERMISSIONS.SETTINGS.UPDATE_SECURITY);
    assertForm(form);
    utils.clearFormErrors(form);

    if (!form.reportValidity()) return false;

    const values = utils.serializeForm(form);
    const errors = validateSecuritySettings(values);

    if (Object.keys(errors).length > 0) {
        utils.applyFormErrors(form, errors);
        return false;
    }

    const payload = {
        session_timeout_minutes: utils.clamp(
            utils.toInteger(values.session_timeout_minutes, 30),
            5,
            1440
        ),
        max_login_attempts: utils.clamp(
            utils.toInteger(values.max_login_attempts, 5),
            1,
            20
        ),
        lockout_minutes: utils.clamp(
            utils.toInteger(values.lockout_minutes, 15),
            1,
            1440
        ),
        minimum_password_length: utils.clamp(
            utils.toInteger(values.minimum_password_length, 8),
            8,
            128
        ),
        require_uppercase: utils.toBoolean(values.require_uppercase),
        require_lowercase: utils.toBoolean(values.require_lowercase),
        require_number: utils.toBoolean(values.require_number),
        require_symbol: utils.toBoolean(values.require_symbol),
        password_expiry_days: utils.clamp(
            utils.toInteger(values.password_expiry_days, 90),
            0,
            365
        ),
        force_https: utils.toBoolean(values.force_https)
    };

    return saveSettingsSection({
        form,
        button,
        endpoint: CONFIG.endpoints.updateSecurity,
        payload,
        permission: PERMISSIONS.SETTINGS.UPDATE_SECURITY,
        stateKey: "security",
        successMessage: "บันทึกการตั้งค่าความปลอดภัยแล้ว"
    });
}

export async function saveAppearanceSettings(form, button = null) {
    permissions.authorize(PERMISSIONS.SETTINGS.UPDATE_APPEARANCE);
    assertForm(form);
    utils.clearFormErrors(form);

    if (!form.reportValidity()) return false;

    const values = utils.serializeForm(form);
    const allowedThemes = ["light", "dark", "system"];
    const allowedDensities = ["comfortable", "compact"];
    const payload = {
        theme: allowedThemes.includes(values.theme) ? values.theme : "light",
        primary_color: normalizeHexColor(values.primary_color, "#2563eb"),
        glass_opacity: utils.clamp(
            utils.toNumber(values.glass_opacity, 0.78),
            0.3,
            1
        ),
        sidebar_collapsed: utils.toBoolean(values.sidebar_collapsed),
        table_density: allowedDensities.includes(values.table_density)
            ? values.table_density
            : "comfortable",
        reduce_motion: utils.toBoolean(values.reduce_motion)
    };

    const saved = await saveSettingsSection({
        form,
        button,
        endpoint: CONFIG.endpoints.updateAppearance,
        payload,
        permission: PERMISSIONS.SETTINGS.UPDATE_APPEARANCE,
        stateKey: "appearance",
        successMessage: "บันทึกการตั้งค่ารูปลักษณ์แล้ว"
    });

    if (saved) applyAppearance(payload);
    return saved;
}

async function saveSettingsSection(options) {
    permissions.authorize(options.permission);

    try {
        await runWithOptionalButtonLoading(options.button, async () => {
            const response = await api.put(options.endpoint, options.payload, {
                requestKey: `settings-${options.stateKey}`
            });

            state.settings[options.stateKey] = {
                ...state.settings[options.stateKey],
                ...(response?.settings || response || options.payload)
            };
        }, { text: "กำลังบันทึก..." });

        components.toast.success(options.successMessage);
        return true;
    } catch (error) {
        if (error.status === 422 && utils.isPlainObject(error.details)) {
            utils.applyFormErrors(options.form, error.details);
            return false;
        }

        handleError(error, "บันทึกการตั้งค่าไม่สำเร็จ");
        return false;
    }
}

export async function createBackup(button = null) {
    permissions.authorize(PERMISSIONS.SETTINGS.BACKUP);

    const confirmed = await components.confirm({
        title: "สร้างข้อมูลสำรอง",
        message: "ระบบจะสำรองฐานข้อมูลและการตั้งค่าปัจจุบัน",
        confirmText: "เริ่มสำรองข้อมูล",
        cancelText: "ยกเลิก"
    });

    if (!confirmed) return false;

    try {
        await runWithOptionalButtonLoading(button, async () => {
            const response = await api.post(CONFIG.endpoints.backup, {}, {
                requestKey: "settings-backup",
                timeout: 120000
            });

            const backupId = response?.id || response?.backup_id;
            const fileName = response?.file_name || response?.fileName;

            if (backupId && response?.download === true) {
                await downloadBackup(backupId, null, fileName);
            }
        }, { text: "กำลังสำรอง..." });

        components.toast.success("สร้างข้อมูลสำรองแล้ว");
        await loadBackupHistory();
        return true;
    } catch (error) {
        handleError(error, "สร้างข้อมูลสำรองไม่สำเร็จ");
        return false;
    }
}

function selectRestoreFile(file) {
    hideError();

    if (!file) {
        clearRestoreFile();
        return;
    }

    const validation = validateRestoreFile(file);

    if (!validation.valid) {
        clearRestoreFile();
        showError(validation.message);
        components.toast.error(validation.message);
        return;
    }

    state.restoreFile = file;
    setText(SELECTORS.restoreFileName, file.name);
    setText(SELECTORS.restoreFileSize, utils.formatFileSize(file.size));
    setHidden(SELECTORS.restoreFileInfo, false);
    updateRestoreButton();
}

function validateRestoreFile(file) {
    const extension = file.name.split(".").pop()?.toLowerCase() || "";

    if (!CONFIG.restoreExtensions.includes(extension)) {
        return { valid: false, message: "รองรับเฉพาะไฟล์ ZIP และ SQL" };
    }

    if (file.size <= 0) {
        return { valid: false, message: "ไฟล์ที่เลือกไม่มีข้อมูล" };
    }

    if (file.size > CONFIG.maximumRestoreSize) {
        return {
            valid: false,
            message: `ขนาดไฟล์ต้องไม่เกิน ${utils.formatFileSize(CONFIG.maximumRestoreSize)}`
        };
    }

    if (file.type && !CONFIG.restoreMimeTypes.includes(file.type)) {
        return { valid: false, message: "ชนิดไฟล์ Backup ไม่ถูกต้อง" };
    }

    return { valid: true, message: "" };
}

function clearRestoreFile() {
    state.restoreFile = null;
    const input = document.querySelector(SELECTORS.restoreInput);
    if (input) input.value = "";
    setText(SELECTORS.restoreFileName, "");
    setText(SELECTORS.restoreFileSize, "");
    setHidden(SELECTORS.restoreFileInfo, true);
    updateRestoreButton();
}

export async function restoreBackup(button = null) {
    permissions.authorize(PERMISSIONS.SETTINGS.RESTORE);

    if (!state.restoreFile) {
        components.toast.warning("กรุณาเลือกไฟล์ Backup");
        return false;
    }

    const confirmed = await components.confirm({
        title: "ยืนยันการกู้คืนระบบ",
        message: "การกู้คืนจะเขียนทับข้อมูลปัจจุบัน กรุณาตรวจสอบไฟล์ให้ถูกต้อง",
        confirmText: "กู้คืนระบบ",
        cancelText: "ยกเลิก",
        variant: "danger",
        closeOnBackdrop: false
    });

    if (!confirmed) return false;

    try {
        await runWithOptionalButtonLoading(button, async () => {
            const formData = new FormData();
            formData.append("backup_file", state.restoreFile);

            await api.upload(CONFIG.endpoints.restore, formData, {
                requestKey: "settings-restore",
                cancelPrevious: false,
                timeout: 300000
            });
        }, { text: "กำลังกู้คืน..." });

        components.toast.success("กู้คืนระบบเรียบร้อยแล้ว");
        clearRestoreFile();
        await Promise.allSettled([loadSettings({ silent: true }), loadBackupHistory()]);
        return true;
    } catch (error) {
        handleError(error, "กู้คืนระบบไม่สำเร็จ");
        return false;
    }
}

export async function loadBackupHistory() {
    if (!permissions.has(PERMISSIONS.SETTINGS.BACKUP)) {
        state.backupHistory = [];
        renderBackupHistory();
        return [];
    }

    try {
        const response = await api.get(CONFIG.endpoints.backupHistory, {
            query: { limit: CONFIG.backupHistoryLimit },
            requestKey: "settings-backup-history",
            cancelPrevious: true
        });

        const items = Array.isArray(response)
            ? response
            : response?.backups || response?.items || [];

        state.backupHistory = items
            .map(normalizeBackupHistoryItem)
            .filter(Boolean);
        renderBackupHistory();
        return state.backupHistory;
    } catch (error) {
        console.error("[ConnectPro Backup History]", error);
        return [];
    }
}

export async function downloadBackup(backupId, button = null, preferredName = "") {
    permissions.authorize(PERMISSIONS.SETTINGS.BACKUP);

    if (!backupId) throw new TypeError("Backup ID ไม่ถูกต้อง");

    await runWithOptionalButtonLoading(button, async () => {
        const blob = await api.download(CONFIG.endpoints.downloadBackup, {
            query: { id: backupId },
            requestKey: `settings-backup-download-${backupId}`,
            timeout: 120000
        });

        const historyItem = state.backupHistory.find(
            (item) => String(item.id) === String(backupId)
        );
        const fileName = preferredName || historyItem?.fileName ||
            `connectpro-backup-${backupId}.zip`;

        utils.downloadBlob(blob, fileName);
    }, { text: "กำลังดาวน์โหลด..." });
}

function populateForms() {
    const generalForm = document.querySelector(SELECTORS.generalForm);
    const securityForm = document.querySelector(SELECTORS.securityForm);
    const appearanceForm = document.querySelector(SELECTORS.appearanceForm);

    if (generalForm) utils.setFormValues(generalForm, state.settings.general);
    if (securityForm) utils.setFormValues(securityForm, state.settings.security);
    if (appearanceForm) {
        utils.setFormValues(appearanceForm, state.settings.appearance);
        applyAppearance(state.settings.appearance);
    }
}

function applyAppearance(settings = {}) {
    const root = document.documentElement;
    const theme = settings.theme || "light";
    const color = normalizeHexColor(settings.primary_color, "#2563eb");
    const opacity = utils.clamp(
        utils.toNumber(settings.glass_opacity, 0.78),
        0.3,
        1
    );

    root.dataset.theme = theme;
    root.style.setProperty("--cp-brand-500", color);
    root.style.setProperty("--cp-glass-opacity", String(opacity));
    document.body.classList.toggle(
        "cp-reduce-motion",
        utils.toBoolean(settings.reduce_motion)
    );
    document.body.classList.toggle(
        "cp-density-compact",
        settings.table_density === "compact"
    );
}

function renderBackupHistory() {
    document.querySelectorAll(SELECTORS.backupHistoryBody).forEach((body) => {
        const fragment = document.createDocumentFragment();

        state.backupHistory.forEach((item) => {
            const row = document.createElement("tr");
            row.append(
                createCell(item.fileName),
                createCell(utils.formatFileSize(item.fileSize)),
                createCell(item.createdBy),
                createCell(utils.formatDateTime(item.createdAt)),
                createBackupStatusCell(item.status),
                createBackupActionCell(item)
            );
            fragment.appendChild(row);
        });

        body.replaceChildren(fragment);
    });

    setHidden(SELECTORS.backupHistoryEmpty, state.backupHistory.length > 0);
    permissions.apply(document);
}

function createBackupStatusCell(status) {
    const cell = document.createElement("td");
    const badge = document.createElement("span");
    const success = status === "completed" || status === "success";
    badge.className = `cp-badge cp-badge--${success ? "success" : "warning"}`;
    badge.textContent = success ? "พร้อมใช้งาน" : utils.capitalize(status);
    cell.appendChild(badge);
    return cell;
}

function createBackupActionCell(item) {
    const cell = document.createElement("td");
    const button = document.createElement("button");
    button.type = "button";
    button.className = "cp-button cp-button--secondary cp-button--small";
    button.textContent = "ดาวน์โหลด";
    button.dataset.backupDownload = String(item.id);
    button.dataset.permission = PERMISSIONS.SETTINGS.BACKUP;
    button.disabled = !["completed", "success"].includes(item.status);
    cell.appendChild(button);
    return cell;
}

function createCell(value) {
    const cell = document.createElement("td");
    cell.textContent = String(value ?? "-");
    return cell;
}

function validateGeneralSettings(values) {
    const errors = {};

    if (!utils.validateLength(utils.normalizeText(values.application_name), {
        min: 2,
        max: 100
    })) {
        errors.application_name = ["ชื่อระบบต้องมี 2-100 ตัวอักษร"];
    }

    if (values.support_email && !utils.isValidEmail(values.support_email)) {
        errors.support_email = ["รูปแบบอีเมลไม่ถูกต้อง"];
    }

    const records = utils.toInteger(values.records_per_page, 0);
    if (records < 10 || records > 100) {
        errors.records_per_page = ["จำนวนรายการต่อหน้าต้องอยู่ระหว่าง 10-100"];
    }

    return errors;
}

function validateSecuritySettings(values) {
    const errors = {};
    const sessionTimeout = utils.toInteger(values.session_timeout_minutes, 0);
    const passwordLength = utils.toInteger(values.minimum_password_length, 0);

    if (sessionTimeout < 5 || sessionTimeout > 1440) {
        errors.session_timeout_minutes = ["Session Timeout ต้องอยู่ระหว่าง 5-1440 นาที"];
    }

    if (passwordLength < 8 || passwordLength > 128) {
        errors.minimum_password_length = ["ความยาวรหัสผ่านต้องอยู่ระหว่าง 8-128 ตัว"];
    }

    return errors;
}

function normalizeSettings(value = {}) {
    return {
        general: utils.isPlainObject(value.general) ? value.general : {},
        security: utils.isPlainObject(value.security) ? value.security : {},
        appearance: utils.isPlainObject(value.appearance) ? value.appearance : {}
    };
}

function normalizeBackupHistoryItem(value) {
    if (!value || typeof value !== "object") return null;
    const id = value.id || value.backup_id;
    if (id === undefined || id === null || id === "") return null;

    return Object.freeze({
        id,
        fileName: String(value.file_name || value.fileName || `backup-${id}.zip`),
        fileSize: Math.max(
            0,
            utils.toNumber(value.file_size ?? value.fileSize, 0)
        ),
        status: String(value.status || "completed").toLowerCase(),
        createdBy: String(
            value.created_by || value.createdBy || value.display_name || "ระบบ"
        ),
        createdAt: value.created_at || value.createdAt || null
    });
}

function normalizeHexColor(value, fallback) {
    const color = String(value || "").trim();
    return /^#[0-9a-f]{6}$/i.test(color) ? color : fallback;
}

function applyPermissionState() {
    permissions.apply(document);
    updateRestoreButton();
}

function updateRestoreButton() {
    const allowed = permissions.has(PERMISSIONS.SETTINGS.RESTORE);
    document.querySelectorAll(SELECTORS.restoreButton).forEach((button) => {
        button.disabled = !state.restoreFile || !allowed;
    });
}

function assertForm(form) {
    if (!(form instanceof HTMLFormElement)) {
        throw new TypeError("ไม่พบฟอร์มการตั้งค่า");
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
        element.textContent = String(value ?? "");
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
    console.error("[ConnectPro Admin Settings]", error);
    components.toast.error(error?.message || fallback);
}

document.addEventListener("DOMContentLoaded", initializeSettings, {
    once: true
});

export default Object.freeze({
    init: initializeSettings,
    load: loadSettings,
    saveGeneral: saveGeneralSettings,
    saveSecurity: saveSecuritySettings,
    saveAppearance: saveAppearanceSettings,
    backup: createBackup,
    restore: restoreBackup,
    loadBackupHistory,
    downloadBackup
});
