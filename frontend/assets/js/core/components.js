/**
 * ConnectPro Shared UI Components
 * File: frontend/assets/js/core/components.js
 *
 * Responsibilities:
 * - Toast notifications
 * - Modal and confirmation dialogs
 * - Dropdown menus
 * - Accessible tabs
 * - Mobile sidebar and overlay
 * - Loading and button states
 * - Password visibility toggle
 * - Copy-to-clipboard actions
 * - Declarative component initialization
 *
 * This module has no dependency on page-specific JavaScript.
 */

"use strict";

const DEFAULT_CONFIG = Object.freeze({
    toastDuration: 5000,
    toastRegionId: "connectproToastRegion",
    modalRootId: "connectproModalRoot",
    sidebarBreakpoint: 1199,
    closeModalOnBackdrop: true,
    closeModalOnEscape: true,
    observeDom: false
});

const UI_EVENTS = Object.freeze({
    TOAST_OPENED: "connectpro:toast-opened",
    TOAST_CLOSED: "connectpro:toast-closed",
    MODAL_OPENED: "connectpro:modal-opened",
    MODAL_CLOSED: "connectpro:modal-closed",
    SIDEBAR_OPENED: "connectpro:sidebar-opened",
    SIDEBAR_CLOSED: "connectpro:sidebar-closed",
    TAB_CHANGED: "connectpro:tab-changed"
});

let configOverrides = {};
let initialized = false;
let observer = null;
let toastSequence = 0;
let modalSequence = 0;
let activeModal = null;
let previousFocusedElement = null;
let documentClickHandler = null;
let documentKeydownHandler = null;

export function configureComponents(overrides = {}) {
    if (!isPlainObject(overrides)) {
        throw new TypeError("Component configuration must be an object.");
    }

    configOverrides = {
        ...configOverrides,
        ...overrides
    };

    return getConfig();
}

/**
 * Initialize all declarative components under a root element.
 */
export function initComponents(options = {}) {
    const root = options.root || document;
    configureComponents(options);

    initDropdowns(root);
    initTabs(root);
    initSidebar(root);
    initPasswordToggles(root);
    initCopyButtons(root);
    initDismissibleElements(root);
    initModalTriggers(root);

    if (!initialized) {
        initialized = true;
        bindGlobalEvents();
    }

    if (getConfig().observeDom) {
        startComponentObserver(document.body);
    }
}

export function destroyComponents() {
    stopComponentObserver();
    closeAllDropdowns();
    closeModal(undefined, { reason: "destroy", restoreFocus: false });

    if (documentClickHandler) {
        document.removeEventListener("click", documentClickHandler);
        documentClickHandler = null;
    }

    if (documentKeydownHandler) {
        document.removeEventListener("keydown", documentKeydownHandler);
        documentKeydownHandler = null;
    }

    initialized = false;
}

/* --------------------------------------------------------------------------
   Toast Notifications
   -------------------------------------------------------------------------- */

export function showToast(options = {}) {
    const config = getConfig();
    const normalized = normalizeToastOptions(options, config);
    const region = getOrCreateToastRegion(config);
    const toastId = `cp-toast-${++toastSequence}`;
    const toast = document.createElement("article");

    toast.id = toastId;
    toast.className = `cp-toast cp-toast--${normalized.type}`;
    toast.setAttribute("role", normalized.type === "error" ? "alert" : "status");
    toast.setAttribute("aria-live", normalized.type === "error" ? "assertive" : "polite");
    toast.dataset.toast = "";

    const icon = document.createElement("span");
    icon.className = "cp-toast__icon";
    icon.setAttribute("aria-hidden", "true");
    icon.innerHTML = getToastIcon(normalized.type);

    const content = document.createElement("div");

    if (normalized.title) {
        const title = document.createElement("div");
        title.className = "cp-toast__title";
        title.textContent = normalized.title;
        content.appendChild(title);
    }

    const message = document.createElement("p");
    message.className = "cp-toast__message";
    message.textContent = normalized.message;
    content.appendChild(message);

    const closeButton = document.createElement("button");
    closeButton.type = "button";
    closeButton.className = "cp-toast__close";
    closeButton.setAttribute("aria-label", "ปิดการแจ้งเตือน");
    closeButton.innerHTML = getCloseIcon();

    toast.append(icon, content, closeButton);
    region.appendChild(toast);

    let timerId = null;
    let remaining = normalized.duration;
    let startedAt = Date.now();

    const close = (reason = "manual") => {
        if (!toast.isConnected) {
            return;
        }

        window.clearTimeout(timerId);
        toast.classList.add("is-closing");

        window.setTimeout(() => {
            toast.remove();
            dispatchUiEvent(UI_EVENTS.TOAST_CLOSED, {
                id: toastId,
                type: normalized.type,
                reason
            });
        }, prefersReducedMotion() ? 0 : 180);
    };

    const startTimer = () => {
        if (normalized.duration <= 0) {
            return;
        }

        startedAt = Date.now();
        timerId = window.setTimeout(() => close("timeout"), remaining);
    };

    const pauseTimer = () => {
        if (!timerId) {
            return;
        }

        window.clearTimeout(timerId);
        timerId = null;
        remaining -= Date.now() - startedAt;
    };

    closeButton.addEventListener("click", () => close("button"));
    toast.addEventListener("mouseenter", pauseTimer);
    toast.addEventListener("mouseleave", startTimer);
    toast.addEventListener("focusin", pauseTimer);
    toast.addEventListener("focusout", startTimer);

    startTimer();

    dispatchUiEvent(UI_EVENTS.TOAST_OPENED, {
        id: toastId,
        type: normalized.type
    });

    return Object.freeze({
        id: toastId,
        element: toast,
        close
    });
}

export const toast = Object.freeze({
    show: showToast,
    success: (message, options = {}) =>
        showToast({ ...options, message, type: "success" }),
    error: (message, options = {}) =>
        showToast({ ...options, message, type: "error" }),
    warning: (message, options = {}) =>
        showToast({ ...options, message, type: "warning" }),
    info: (message, options = {}) =>
        showToast({ ...options, message, type: "info" }),
    clear: clearToasts
});

export function clearToasts() {
    document.querySelectorAll("[data-toast]").forEach((element) => element.remove());
}

/* --------------------------------------------------------------------------
   Modal and Confirmation Dialog
   -------------------------------------------------------------------------- */

export function openModal(options = {}) {
    const config = getConfig();
    const normalized = normalizeModalOptions(options);

    if (activeModal) {
        closeModal(undefined, { reason: "replace", restoreFocus: false });
    }

    previousFocusedElement = document.activeElement;

    const modalId = normalized.id || `cp-modal-${++modalSequence}`;
    const root = getOrCreateModalRoot(config);
    const backdrop = document.createElement("div");
    const modal = document.createElement("section");

    backdrop.className = "cp-glass-modal-backdrop";
    backdrop.dataset.modalBackdrop = modalId;

    modal.id = modalId;
    modal.className = `cp-glass-modal ${normalized.className}`.trim();
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("tabindex", "-1");
    modal.dataset.modal = "";

    const header = createModalHeader(normalized, modalId);
    const body = document.createElement("div");
    body.className = "cp-glass-modal__body";
    appendContent(body, normalized.content);

    const footer = createModalFooter(normalized.actions);

    modal.appendChild(header);
    modal.appendChild(body);

    if (footer) {
        modal.appendChild(footer);
    }

    backdrop.appendChild(modal);
    root.appendChild(backdrop);
    document.body.classList.add("cp-modal-open");

    activeModal = {
        id: modalId,
        root,
        backdrop,
        modal,
        onClose: normalized.onClose,
        closeOnBackdrop: normalized.closeOnBackdrop ?? config.closeModalOnBackdrop,
        closeOnEscape: normalized.closeOnEscape ?? config.closeModalOnEscape
    };

    modal.querySelectorAll("[data-modal-close]").forEach((button) => {
        button.addEventListener("click", () => closeModal(modalId, { reason: "button" }));
    });

    backdrop.addEventListener("mousedown", (event) => {
        if (event.target === backdrop && activeModal?.closeOnBackdrop) {
            closeModal(modalId, { reason: "backdrop" });
        }
    });

    window.requestAnimationFrame(() => {
        backdrop.classList.add("is-open");
        focusFirstElement(modal);
    });

    dispatchUiEvent(UI_EVENTS.MODAL_OPENED, { id: modalId });

    return Object.freeze({
        id: modalId,
        element: modal,
        close: (reason = "api") => closeModal(modalId, { reason })
    });
}

export function closeModal(modalId, options = {}) {
    if (!activeModal) {
        return false;
    }

    if (modalId && activeModal.id !== modalId) {
        return false;
    }

    const closingModal = activeModal;
    activeModal = null;
    closingModal.backdrop.classList.remove("is-open");
    document.body.classList.remove("cp-modal-open");

    const finish = () => {
        closingModal.backdrop.remove();
        closingModal.onClose?.(options.reason || "api");

        if (options.restoreFocus !== false && previousFocusedElement?.focus) {
            previousFocusedElement.focus({ preventScroll: true });
        }

        previousFocusedElement = null;
        dispatchUiEvent(UI_EVENTS.MODAL_CLOSED, {
            id: closingModal.id,
            reason: options.reason || "api"
        });
    };

    window.setTimeout(finish, prefersReducedMotion() ? 0 : 180);
    return true;
}

export function confirmDialog(options = {}) {
    const normalized = {
        title: options.title || "ยืนยันการดำเนินการ",
        message: options.message || "ต้องการดำเนินการต่อหรือไม่",
        confirmText: options.confirmText || "ยืนยัน",
        cancelText: options.cancelText || "ยกเลิก",
        variant: options.variant || "primary",
        icon: options.icon !== false
    };

    return new Promise((resolve) => {
        let settled = false;
        const content = document.createElement("div");
        content.className = "cp-dialog-content";

        if (normalized.icon) {
            const icon = document.createElement("div");
            icon.className = `cp-dialog-icon${normalized.variant === "danger" ? " cp-dialog-icon--danger" : ""}`;
            icon.setAttribute("aria-hidden", "true");
            icon.innerHTML = normalized.variant === "danger"
                ? getWarningIcon()
                : getQuestionIcon();
            content.appendChild(icon);
        }

        const description = document.createElement("p");
        description.className = "cp-dialog-description";
        description.textContent = normalized.message;
        content.appendChild(description);

        const modalHandle = openModal({
            title: normalized.title,
            content,
            closeOnBackdrop: options.closeOnBackdrop ?? false,
            className: "cp-glass-modal--small",
            actions: [
                {
                    label: normalized.cancelText,
                    className: "cp-button cp-button--secondary",
                    onClick: () => {
                        settled = true;
                        modalHandle.close("cancel");
                        resolve(false);
                    }
                },
                {
                    label: normalized.confirmText,
                    className: normalized.variant === "danger"
                        ? "cp-button cp-button--danger"
                        : "cp-button cp-button--primary",
                    autofocus: true,
                    onClick: () => {
                        settled = true;
                        modalHandle.close("confirm");
                        resolve(true);
                    }
                }
            ],
            onClose: () => {
                if (!settled) {
                    settled = true;
                    resolve(false);
                }
            }
        });
    });
}

/* --------------------------------------------------------------------------
   Loading and Button States
   -------------------------------------------------------------------------- */

export function setButtonLoading(button, loading, options = {}) {
    if (!(button instanceof HTMLElement)) {
        throw new TypeError("setButtonLoading() requires an HTML element.");
    }

    if (loading) {
        if (button.dataset.loading === "true") {
            return;
        }

        button.dataset.loading = "true";
        button.dataset.originalHtml = button.innerHTML;
        button.dataset.originalDisabled = String(Boolean(button.disabled));
        button.disabled = true;
        button.setAttribute("aria-busy", "true");
        button.innerHTML = `${getSpinnerMarkup()}<span>${escapeHtml(options.text || "กำลังดำเนินการ...")}</span>`;
        return;
    }

    if (button.dataset.loading !== "true") {
        return;
    }

    button.innerHTML = button.dataset.originalHtml || "";
    button.disabled = button.dataset.originalDisabled === "true";
    button.removeAttribute("aria-busy");
    delete button.dataset.loading;
    delete button.dataset.originalHtml;
    delete button.dataset.originalDisabled;
}

export async function withButtonLoading(button, task, options = {}) {
    if (typeof task !== "function") {
        throw new TypeError("withButtonLoading() requires a task function.");
    }

    setButtonLoading(button, true, options);

    try {
        return await task();
    } finally {
        setButtonLoading(button, false);
    }
}

export function setContainerLoading(container, loading, options = {}) {
    if (!(container instanceof HTMLElement)) {
        throw new TypeError("setContainerLoading() requires an HTML element.");
    }

    let overlay = container.querySelector(":scope > [data-loading-overlay]");

    if (!loading) {
        overlay?.remove();
        container.classList.remove("is-loading");
        container.removeAttribute("aria-busy");
        return;
    }

    container.classList.add("is-loading");
    container.setAttribute("aria-busy", "true");

    if (!overlay) {
        overlay = document.createElement("div");
        overlay.className = "cp-loading-overlay";
        overlay.dataset.loadingOverlay = "";
        overlay.innerHTML = `${getSpinnerMarkup()}<span>${escapeHtml(options.text || "กำลังโหลดข้อมูล...")}</span>`;
        container.appendChild(overlay);
    }
}

/* --------------------------------------------------------------------------
   Dropdowns
   -------------------------------------------------------------------------- */

export function initDropdowns(root = document) {
    root.querySelectorAll("[data-dropdown-toggle]").forEach((toggle) => {
        if (toggle.dataset.componentReady === "dropdown") {
            return;
        }

        const menuId = toggle.getAttribute("aria-controls") || toggle.dataset.dropdownToggle;
        const menu = menuId ? document.getElementById(menuId) : null;

        if (!menu) {
            return;
        }

        toggle.dataset.componentReady = "dropdown";
        toggle.setAttribute("aria-expanded", "false");
        menu.hidden = true;

        toggle.addEventListener("click", (event) => {
            event.stopPropagation();
            const shouldOpen = toggle.getAttribute("aria-expanded") !== "true";
            closeAllDropdowns(toggle);
            setDropdownState(toggle, menu, shouldOpen);
        });

        menu.addEventListener("keydown", (event) => handleDropdownKeyboard(event, toggle, menu));
    });
}

export function closeAllDropdowns(exceptToggle = null) {
    document.querySelectorAll("[data-dropdown-toggle][aria-expanded=\"true\"]").forEach((toggle) => {
        if (toggle === exceptToggle) {
            return;
        }

        const menuId = toggle.getAttribute("aria-controls") || toggle.dataset.dropdownToggle;
        const menu = menuId ? document.getElementById(menuId) : null;
        setDropdownState(toggle, menu, false);
    });
}

/* --------------------------------------------------------------------------
   Tabs
   -------------------------------------------------------------------------- */

export function initTabs(root = document) {
    root.querySelectorAll("[data-tabs]").forEach((tabList) => {
        if (tabList.dataset.componentReady === "tabs") {
            return;
        }

        const tabs = getTabs(tabList);

        if (tabs.length === 0) {
            return;
        }

        tabList.dataset.componentReady = "tabs";
        tabList.setAttribute("role", "tablist");

        tabs.forEach((tab, index) => {
            const panel = getTabPanel(tab);
            tab.setAttribute("role", "tab");
            tab.setAttribute("tabindex", index === 0 ? "0" : "-1");

            if (panel) {
                panel.setAttribute("role", "tabpanel");
                panel.setAttribute("aria-labelledby", tab.id);
            }

            tab.addEventListener("click", () => activateTab(tabList, tab));
            tab.addEventListener("keydown", (event) => handleTabKeyboard(event, tabList));
        });

        const selected = tabs.find((tab) => tab.getAttribute("aria-selected") === "true") || tabs[0];
        activateTab(tabList, selected, { focus: false, emit: false });
    });
}

export function activateTab(tabList, selectedTab, options = {}) {
    const tabs = getTabs(tabList);

    tabs.forEach((tab) => {
        const selected = tab === selectedTab;
        const panel = getTabPanel(tab);
        tab.setAttribute("aria-selected", String(selected));
        tab.setAttribute("tabindex", selected ? "0" : "-1");
        tab.classList.toggle("is-active", selected);

        if (panel) {
            panel.hidden = !selected;
        }
    });

    if (options.focus !== false) {
        selectedTab.focus({ preventScroll: true });
    }

    if (options.emit !== false) {
        dispatchUiEvent(UI_EVENTS.TAB_CHANGED, {
            tabId: selectedTab.id,
            panelId: selectedTab.getAttribute("aria-controls")
        });
    }
}

/* --------------------------------------------------------------------------
   Mobile Sidebar
   -------------------------------------------------------------------------- */

export function initSidebar(root = document) {
    root.querySelectorAll("[data-sidebar-toggle]").forEach((toggle) => {
        if (toggle.dataset.componentReady === "sidebar") {
            return;
        }

        const sidebarId = toggle.getAttribute("aria-controls") || toggle.dataset.sidebarToggle;
        const sidebar = sidebarId ? document.getElementById(sidebarId) : null;

        if (!sidebar) {
            return;
        }

        toggle.dataset.componentReady = "sidebar";
        toggle.setAttribute("aria-expanded", String(sidebar.classList.contains("is-open")));
        toggle.addEventListener("click", () => toggleSidebar(sidebar, toggle));

        sidebar.querySelectorAll("[data-sidebar-close]").forEach((button) => {
            button.addEventListener("click", () => closeSidebar(sidebar, toggle));
        });
    });
}

export function openSidebar(sidebar, toggle = null) {
    const element = resolveElement(sidebar);

    if (!element) {
        return false;
    }

    const overlay = getOrCreateMobileOverlay();
    element.classList.add("is-open");
    element.setAttribute("aria-hidden", "false");
    overlay.classList.add("is-open");
    document.body.classList.add("cp-sidebar-open");
    toggle?.setAttribute("aria-expanded", "true");

    overlay.onclick = () => closeSidebar(element, toggle);
    dispatchUiEvent(UI_EVENTS.SIDEBAR_OPENED, { id: element.id });
    return true;
}

export function closeSidebar(sidebar, toggle = null) {
    const element = resolveElement(sidebar);

    if (!element) {
        return false;
    }

    element.classList.remove("is-open");
    element.setAttribute("aria-hidden", "true");
    document.querySelector(".cp-mobile-overlay")?.classList.remove("is-open");
    document.body.classList.remove("cp-sidebar-open");
    toggle?.setAttribute("aria-expanded", "false");
    dispatchUiEvent(UI_EVENTS.SIDEBAR_CLOSED, { id: element.id });
    return true;
}

export function toggleSidebar(sidebar, toggle = null) {
    const element = resolveElement(sidebar);

    if (!element) {
        return false;
    }

    return element.classList.contains("is-open")
        ? closeSidebar(element, toggle)
        : openSidebar(element, toggle);
}

/* --------------------------------------------------------------------------
   Utility Components
   -------------------------------------------------------------------------- */

export function initPasswordToggles(root = document) {
    root.querySelectorAll("[data-password-toggle]").forEach((button) => {
        if (button.dataset.componentReady === "password") {
            return;
        }

        const inputId = button.getAttribute("aria-controls") || button.dataset.passwordToggle;
        const input = inputId ? document.getElementById(inputId) : null;

        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        button.dataset.componentReady = "password";
        button.setAttribute("aria-pressed", "false");
        button.addEventListener("click", () => {
            const visible = input.type === "text";
            input.type = visible ? "password" : "text";
            button.setAttribute("aria-pressed", String(!visible));
            button.setAttribute("aria-label", visible ? "แสดงรหัสผ่าน" : "ซ่อนรหัสผ่าน");
        });
    });
}

export function initCopyButtons(root = document) {
    root.querySelectorAll("[data-copy-target], [data-copy-value]").forEach((button) => {
        if (button.dataset.componentReady === "copy") {
            return;
        }

        button.dataset.componentReady = "copy";
        button.addEventListener("click", async () => {
            const value = getCopyValue(button);

            if (!value) {
                toast.warning("ไม่มีข้อมูลสำหรับคัดลอก");
                return;
            }

            try {
                await copyText(value);
                toast.success(button.dataset.copySuccess || "คัดลอกข้อมูลแล้ว", {
                    duration: 2200
                });
            } catch {
                toast.error("ไม่สามารถคัดลอกข้อมูลได้");
            }
        });
    });
}

export function initDismissibleElements(root = document) {
    root.querySelectorAll("[data-dismiss]").forEach((button) => {
        if (button.dataset.componentReady === "dismiss") {
            return;
        }

        button.dataset.componentReady = "dismiss";
        button.addEventListener("click", () => {
            const selector = button.dataset.dismiss;
            const target = selector
                ? button.closest(selector) || document.querySelector(selector)
                : button.parentElement;
            target?.remove();
        });
    });
}

export function initModalTriggers(root = document) {
    root.querySelectorAll("[data-modal-open]").forEach((button) => {
        if (button.dataset.componentReady === "modal-trigger") {
            return;
        }

        button.dataset.componentReady = "modal-trigger";
        button.addEventListener("click", () => {
            const templateId = button.dataset.modalOpen;
            const template = document.getElementById(templateId);

            if (!template) {
                return;
            }

            const content = template instanceof HTMLTemplateElement
                ? template.content.cloneNode(true)
                : template.cloneNode(true);

            openModal({
                title: button.dataset.modalTitle || template.dataset.modalTitle || "รายละเอียด",
                content
            });
        });
    });
}

export async function copyText(value) {
    const text = String(value);

    if (navigator.clipboard?.writeText && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
    }

    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.setAttribute("readonly", "");
    textarea.style.position = "fixed";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand("copy");
    textarea.remove();

    if (!copied) {
        throw new Error("Copy command failed.");
    }

    return true;
}

export function startComponentObserver(root = document.body) {
    stopComponentObserver();

    if (!root || !("MutationObserver" in window)) {
        return false;
    }

    observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    initComponents({ root: node, observeDom: false });
                }
            });
        }
    });

    observer.observe(root, { childList: true, subtree: true });
    return true;
}

export function stopComponentObserver() {
    observer?.disconnect();
    observer = null;
}

/* --------------------------------------------------------------------------
   Internal Helpers
   -------------------------------------------------------------------------- */

function bindGlobalEvents() {
    documentClickHandler = (event) => {
        if (!event.target.closest("[data-dropdown]")) {
            closeAllDropdowns();
        }
    };

    documentKeydownHandler = (event) => {
        if (event.key === "Escape") {
            closeAllDropdowns();

            if (activeModal?.closeOnEscape) {
                closeModal(activeModal.id, { reason: "escape" });
            }

            const openSidebarElement = document.querySelector(
                ".cp-glass-sidebar.is-open, .cp-admin-sidebar.is-open, .cp-user-sidebar.is-open"
            );

            if (openSidebarElement) {
                closeSidebar(openSidebarElement);
            }
        }

        if (event.key === "Tab" && activeModal) {
            trapFocus(event, activeModal.modal);
        }
    };

    document.addEventListener("click", documentClickHandler);
    document.addEventListener("keydown", documentKeydownHandler);

    window.addEventListener("resize", () => {
        if (window.innerWidth > getConfig().sidebarBreakpoint) {
            document.querySelector(".cp-mobile-overlay")?.classList.remove("is-open");
            document.body.classList.remove("cp-sidebar-open");
        }
    });
}

function createModalHeader(options, modalId) {
    const header = document.createElement("header");
    header.className = "cp-glass-modal__header";

    const title = document.createElement("h2");
    title.id = `${modalId}-title`;
    title.className = "cp-dialog-title";
    title.textContent = options.title;

    const closeButton = document.createElement("button");
    closeButton.type = "button";
    closeButton.className = "cp-icon-button";
    closeButton.dataset.modalClose = "";
    closeButton.setAttribute("aria-label", "ปิดหน้าต่าง");
    closeButton.innerHTML = getCloseIcon();

    header.append(title, closeButton);
    return header;
}

function createModalFooter(actions) {
    if (!Array.isArray(actions) || actions.length === 0) {
        return null;
    }

    const footer = document.createElement("footer");
    footer.className = "cp-glass-modal__footer cp-dialog-actions";

    actions.forEach((action) => {
        const button = document.createElement("button");
        button.type = action.type || "button";
        button.className = action.className || "cp-button cp-button--secondary";
        button.textContent = action.label || "ตกลง";

        if (action.autofocus) {
            button.dataset.autofocus = "";
        }

        button.addEventListener("click", async () => {
            if (typeof action.onClick !== "function") {
                return;
            }

            await withButtonLoading(button, () => action.onClick(button), {
                text: action.loadingText || "กำลังดำเนินการ..."
            });
        });

        footer.appendChild(button);
    });

    return footer;
}

function appendContent(container, content) {
    if (content instanceof Node) {
        container.appendChild(content);
        return;
    }

    const paragraph = document.createElement("p");
    paragraph.textContent = String(content || "");
    container.appendChild(paragraph);
}

function normalizeToastOptions(options, config) {
    const value = typeof options === "string" ? { message: options } : options;
    const types = ["success", "error", "warning", "info"];

    return {
        message: String(value.message || "มีการแจ้งเตือนจากระบบ"),
        title: value.title ? String(value.title) : "",
        type: types.includes(value.type) ? value.type : "info",
        duration: Number.isFinite(Number(value.duration))
            ? Math.max(0, Number(value.duration))
            : config.toastDuration
    };
}

function normalizeModalOptions(options) {
    return {
        id: options.id || null,
        title: String(options.title || "รายละเอียด"),
        content: options.content || "",
        actions: options.actions || [],
        className: options.className || "",
        closeOnBackdrop: options.closeOnBackdrop,
        closeOnEscape: options.closeOnEscape,
        onClose: typeof options.onClose === "function" ? options.onClose : null
    };
}

function getOrCreateToastRegion(config) {
    let region = document.getElementById(config.toastRegionId);

    if (!region) {
        region = document.createElement("div");
        region.id = config.toastRegionId;
        region.className = "cp-toast-region";
        region.setAttribute("aria-label", "การแจ้งเตือน");
        document.body.appendChild(region);
    }

    return region;
}

function getOrCreateModalRoot(config) {
    let root = document.getElementById(config.modalRootId);

    if (!root) {
        root = document.createElement("div");
        root.id = config.modalRootId;
        document.body.appendChild(root);
    }

    return root;
}

function getOrCreateMobileOverlay() {
    let overlay = document.querySelector(".cp-mobile-overlay");

    if (!overlay) {
        overlay = document.createElement("div");
        overlay.className = "cp-mobile-overlay";
        overlay.setAttribute("aria-hidden", "true");
        document.body.appendChild(overlay);
    }

    return overlay;
}

function setDropdownState(toggle, menu, open) {
    if (!toggle || !menu) {
        return;
    }

    toggle.setAttribute("aria-expanded", String(open));
    menu.hidden = !open;
    menu.classList.toggle("is-open", open);

    if (open) {
        getFocusableElements(menu)[0]?.focus({ preventScroll: true });
    }
}

function handleDropdownKeyboard(event, toggle, menu) {
    const items = getFocusableElements(menu);
    const currentIndex = items.indexOf(document.activeElement);

    if (event.key === "Escape") {
        event.preventDefault();
        setDropdownState(toggle, menu, false);
        toggle.focus();
    }

    if (event.key === "ArrowDown") {
        event.preventDefault();
        items[(currentIndex + 1) % items.length]?.focus();
    }

    if (event.key === "ArrowUp") {
        event.preventDefault();
        items[(currentIndex - 1 + items.length) % items.length]?.focus();
    }
}

function getTabs(tabList) {
    return [...tabList.querySelectorAll(":scope > [data-tab], :scope > [role=\"tab\"]")];
}

function getTabPanel(tab) {
    const panelId = tab.getAttribute("aria-controls") || tab.dataset.tab;
    return panelId ? document.getElementById(panelId) : null;
}

function handleTabKeyboard(event, tabList) {
    const tabs = getTabs(tabList);
    const currentIndex = tabs.indexOf(document.activeElement);
    let nextIndex = currentIndex;

    if (["ArrowRight", "ArrowDown"].includes(event.key)) {
        nextIndex = (currentIndex + 1) % tabs.length;
    } else if (["ArrowLeft", "ArrowUp"].includes(event.key)) {
        nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
    } else if (event.key === "Home") {
        nextIndex = 0;
    } else if (event.key === "End") {
        nextIndex = tabs.length - 1;
    } else {
        return;
    }

    event.preventDefault();
    activateTab(tabList, tabs[nextIndex]);
}

function trapFocus(event, container) {
    const focusable = getFocusableElements(container);

    if (focusable.length === 0) {
        event.preventDefault();
        container.focus();
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function focusFirstElement(container) {
    const autofocus = container.querySelector("[data-autofocus]");
    (autofocus || getFocusableElements(container)[0] || container).focus({
        preventScroll: true
    });
}

function getFocusableElements(container) {
    return [...container.querySelectorAll(
        "a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\"-1\"])"
    )].filter((element) => !element.hidden && element.offsetParent !== null);
}

function getCopyValue(button) {
    if (button.dataset.copyValue) {
        return button.dataset.copyValue;
    }

    const target = document.querySelector(button.dataset.copyTarget || "");

    if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
        return target.value;
    }

    return target?.textContent?.trim() || "";
}

function resolveElement(value) {
    if (value instanceof HTMLElement) {
        return value;
    }

    if (typeof value === "string") {
        return document.querySelector(value.startsWith("#") ? value : `#${value}`);
    }

    return null;
}

function getConfig() {
    return { ...DEFAULT_CONFIG, ...configOverrides };
}

function dispatchUiEvent(eventName, detail) {
    window.dispatchEvent(new CustomEvent(eventName, { detail }));
}

function prefersReducedMotion() {
    return window.matchMedia?.("(prefers-reduced-motion: reduce)").matches === true;
}

function escapeHtml(value) {
    const span = document.createElement("span");
    span.textContent = String(value);
    return span.innerHTML;
}

function isPlainObject(value) {
    return value !== null &&
        typeof value === "object" &&
        Object.getPrototypeOf(value) === Object.prototype;
}

function getSpinnerMarkup() {
    return '<span class="cp-spinner" aria-hidden="true"></span>';
}

function getCloseIcon() {
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
}

function getToastIcon(type) {
    const icons = {
        success: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg>',
        error: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg>',
        warning: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3 2 21h20L12 3Z"/><path d="M12 9v4m0 4h.01"/></svg>',
        info: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg>'
    };

    return icons[type] || icons.info;
}

function getWarningIcon() {
    return getToastIcon("warning");
}

function getQuestionIcon() {
    return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.4 2.4 0 0 1 4.6 1c0 2-2.4 2.1-2.4 4m0 3h.01"/></svg>';
}

const components = Object.freeze({
    configure: configureComponents,
    init: initComponents,
    destroy: destroyComponents,
    toast,
    showToast,
    clearToasts,
    openModal,
    closeModal,
    confirm: confirmDialog,
    setButtonLoading,
    withButtonLoading,
    setContainerLoading,
    initDropdowns,
    closeAllDropdowns,
    initTabs,
    activateTab,
    initSidebar,
    openSidebar,
    closeSidebar,
    toggleSidebar,
    initPasswordToggles,
    initCopyButtons,
    initDismissibleElements,
    initModalTriggers,
    copyText,
    startObserver: startComponentObserver,
    stopObserver: stopComponentObserver,
    events: UI_EVENTS
});

export { UI_EVENTS };
export default components;
