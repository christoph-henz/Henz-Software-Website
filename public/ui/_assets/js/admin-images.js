(function () {
    'use strict';

    var cfg = window.__ADMIN_IMAGES_CONFIG || {};
    var root = document.getElementById('adminImagesRoot');
    if (!root) {
        return;
    }

    var uploadForm = document.getElementById('adminImagesUploadForm');
    var uploadFile = document.getElementById('adminImagesUploadFile');
    var uploadAltText = document.getElementById('adminImagesUploadAltText');
    var uploadHint = document.getElementById('adminImagesUploadHint');
    var uploadProgressWrap = document.getElementById('adminImagesUploadProgressWrap');
    var uploadProgressBar = document.getElementById('adminImagesUploadProgressBar');
    var uploadProgressText = document.getElementById('adminImagesUploadProgressText');
    var uploadProgressTrack = uploadProgressWrap ? uploadProgressWrap.querySelector('.admin-images-progress-track') : null;
    var uploadSubmitBtn = uploadForm ? uploadForm.querySelector('button[type="submit"]') : null;
    var refreshBtn = document.getElementById('adminImagesRefreshBtn');
    var grid = document.getElementById('adminImagesGrid');
    var detailContent = document.getElementById('adminImagesDetailContent');
    var assignTemplate = document.getElementById('adminImagesAssignModalTemplate');

    var state = {
        assets: [],
        selectedId: null,
        isLoading: false
    };

    attachHandlers();
    loadAssets();

    function attachHandlers() {
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                loadAssets();
            });
        }

        if (uploadForm) {
            uploadForm.addEventListener('submit', onUploadSubmit);
        }

        if (grid) {
            grid.addEventListener('click', function (event) {
                var selectBtn = event.target && event.target.closest('[data-select-id]');
                if (selectBtn) {
                    event.preventDefault();
                    selectAsset(parseInt(selectBtn.getAttribute('data-select-id') || '0', 10));
                    return;
                }

                var toggleBtn = event.target && event.target.closest('[data-toggle-id]');
                if (toggleBtn) {
                    event.preventDefault();
                    var assetId = parseInt(toggleBtn.getAttribute('data-toggle-id') || '0', 10);
                    var nextActive = toggleBtn.getAttribute('data-next-active') === '1';
                    updateAsset(assetId, { is_active: nextActive }).then(function (ok) {
                        if (ok) {
                            window.adminShowNotification && window.adminShowNotification('success', 'Status wurde gespeichert.');
                            loadAssets(false);
                        }
                    });
                    return;
                }
            });
        }

        if (detailContent) {
            detailContent.addEventListener('submit', function (event) {
                var form = event.target;
                if (!form || form.id !== 'adminImagesDetailForm') {
                    return;
                }

                event.preventDefault();
                var assetId = parseInt(form.getAttribute('data-asset-id') || '0', 10);
                var altInput = form.querySelector('[name="alt_text"]');
                var activeInput = form.querySelector('[name="is_active"]');

                updateAsset(assetId, {
                    alt_text: altInput ? String(altInput.value || '') : '',
                    is_active: !!(activeInput && activeInput.checked)
                }).then(function (ok) {
                    if (ok) {
                        window.adminShowNotification && window.adminShowNotification('success', 'Asset-Details gespeichert.');
                        loadAssets(false);
                    }
                });
            });

            detailContent.addEventListener('click', function (event) {
                var assignBtn = event.target && event.target.closest('[data-assign-id]');
                if (assignBtn) {
                    event.preventDefault();
                    var assetId = parseInt(assignBtn.getAttribute('data-assign-id') || '0', 10);
                    openAssignModal(assetId);
                    return;
                }

                var deleteBtn = event.target && event.target.closest('[data-delete-id]');
                if (deleteBtn) {
                    event.preventDefault();
                    var deleteId = parseInt(deleteBtn.getAttribute('data-delete-id') || '0', 10);
                    destroyAsset(deleteId);
                }
            });
        }
    }

    function onUploadSubmit(event) {
        event.preventDefault();

        var file = uploadFile && uploadFile.files ? uploadFile.files[0] : null;
        var validationError = validateUpload(file);
        if (validationError !== '') {
            setUploadHint(validationError);
            window.adminShowNotification && window.adminShowNotification('warning', validationError);
            return;
        }

        var altText = String(uploadAltText && uploadAltText.value ? uploadAltText.value : '');
        var chunkSize = getChunkSizeBytes();

        setUploadHint('Upload wird vorbereitet...');
        setUploadBusy(true);
        setUploadProgress(0, file.size);

        uploadFileInChunks(file, altText, chunkSize).then(function (result) {
            if (!result.ok) {
                var message = buildApiErrorMessage(result, 'Upload fehlgeschlagen.');
                setUploadHint(message);
                window.adminShowNotification && window.adminShowNotification('error', message);
                return;
            }

            if (uploadForm) {
                uploadForm.reset();
            }

            setUploadProgress(file.size, file.size);
            setUploadHint('Upload erfolgreich.');
            window.adminShowNotification && window.adminShowNotification('success', 'Bild wurde hochgeladen.');
            loadAssets();
        }).catch(function () {
            var message = 'Upload fehlgeschlagen. Bitte erneut versuchen.';
            setUploadHint(message);
            window.adminShowNotification && window.adminShowNotification('error', message);
        }).finally(function () {
            setUploadBusy(false);
        });
    }

    function uploadFileInChunks(file, altText, chunkSize) {
        var initPayload = {
            filename: String(file.name || 'upload.bin'),
            mime_type: String(file.type || 'application/octet-stream'),
            total_size: Number(file.size || 0),
            alt_text: altText
        };

        return fetchJson(apiPath('assets_upload_init'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(initPayload)
        }).then(function (initResult) {
            if (!initResult.ok) {
                return initResult;
            }

            var initData = initResult.json && initResult.json.data ? initResult.json.data : {};
            var uploadId = trim(initData.upload_id);
            if (uploadId === '') {
                return {
                    ok: false,
                    status: 500,
                    json: { message: 'Upload-Session konnte nicht gestartet werden.' }
                };
            }

            var totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
            var sequence = Promise.resolve();

            for (var chunkIndex = 0; chunkIndex < totalChunks; chunkIndex += 1) {
                (function (currentChunkIndex) {
                    sequence = sequence.then(function () {
                        var start = currentChunkIndex * chunkSize;
                        var end = Math.min(start + chunkSize, file.size);
                        var chunkBlob = file.slice(start, end);

                        setUploadHint('Upload läuft... Chunk ' + (currentChunkIndex + 1) + ' von ' + totalChunks + '.');

                        return fetchJson(apiPath('assets_upload_chunk', uploadId), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/octet-stream',
                                'X-Chunk-Index': String(currentChunkIndex)
                            },
                            body: chunkBlob
                        }).then(function (chunkResult) {
                            if (!chunkResult.ok) {
                                throw chunkResult;
                            }

                            var uploadedBytes = end;
                            setUploadProgress(uploadedBytes, file.size);
                        });
                    });
                }(chunkIndex));
            }

            return sequence.then(function () {
                setUploadHint('Upload wird finalisiert...');

                return fetchJson(apiPath('assets_upload_finish', uploadId), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        upload_id: uploadId
                    })
                });
            }).catch(function (errorResult) {
                if (errorResult && typeof errorResult === 'object' && Object.prototype.hasOwnProperty.call(errorResult, 'ok')) {
                    return errorResult;
                }

                return {
                    ok: false,
                    status: 500,
                    json: { message: 'Chunk-Upload fehlgeschlagen.' }
                };
            });
        });
    }

    function setUploadHint(message) {
        if (uploadHint) {
            uploadHint.textContent = String(message || '');
        }
    }

    function setUploadBusy(isBusy) {
        if (uploadFile) {
            uploadFile.disabled = !!isBusy;
        }
        if (uploadAltText) {
            uploadAltText.disabled = !!isBusy;
        }
        if (uploadSubmitBtn) {
            uploadSubmitBtn.disabled = !!isBusy;
        }
    }

    function setUploadProgress(uploadedBytes, totalBytes) {
        if (!uploadProgressWrap || !uploadProgressBar || !uploadProgressText) {
            return;
        }

        var total = Math.max(1, Number(totalBytes || 0));
        var uploaded = Math.max(0, Math.min(Number(uploadedBytes || 0), total));
        var percent = Math.round((uploaded / total) * 100);

        uploadProgressWrap.hidden = false;
        uploadProgressBar.style.width = String(percent) + '%';
        if (uploadProgressTrack) {
            uploadProgressTrack.setAttribute('aria-valuenow', String(percent));
        }

        uploadProgressText.textContent = percent + '% (' + formatBytes(uploaded) + ' / ' + formatBytes(total) + ')';

        if (percent >= 100) {
            window.setTimeout(function () {
                uploadProgressWrap.hidden = true;
            }, 1200);
        }
    }

    function getChunkSizeBytes() {
        var configured = parseInt(String(cfg.upload_chunk_size_bytes || 0), 10);
        if (Number.isFinite(configured) && configured > 0) {
            return configured;
        }

        return 500 * 1024;
    }

    function validateUpload(file) {
        if (!file) {
            return 'Bitte wählen Sie eine Bilddatei aus.';
        }

        var allowedTypes = Array.isArray(cfg.allowed_mime_types) ? cfg.allowed_mime_types : [];
        var maxSize = parseInt(String(cfg.max_file_size_bytes || 0), 10);

        if (allowedTypes.length > 0 && allowedTypes.indexOf(String(file.type || '')) === -1) {
            return 'Dateityp nicht erlaubt. Erlaubt sind JPG, PNG, WebP und GIF.';
        }

        if (Number.isFinite(maxSize) && maxSize > 0 && file.size > maxSize) {
            return 'Datei ist zu gross. Maximal ' + String(cfg.max_file_size_label || '5 MB') + '.';
        }

        return '';
    }

    function loadAssets(resetSelection) {
        if (state.isLoading) {
            return;
        }

        if (resetSelection !== false) {
            state.selectedId = null;
        }

        state.isLoading = true;
        renderGrid();

        fetchJson(apiPath('assets_list'), {
            method: 'GET'
        }).then(function (result) {
            state.isLoading = false;
            if (!result.ok) {
                state.assets = [];
                renderGrid();
                renderDetail(null);
                var message = buildApiErrorMessage(result, 'Assets konnten nicht geladen werden.');
                window.adminShowNotification && window.adminShowNotification('error', message);
                return;
            }

            state.assets = normalizeCollection(result.json && result.json.data);

            if (state.selectedId === null && state.assets.length > 0) {
                state.selectedId = parseInt(String(state.assets[0].id || 0), 10) || null;
            }

            renderGrid();
            renderDetail(getSelectedAsset());
        }).catch(function () {
            state.isLoading = false;
            state.assets = [];
            renderGrid();
            renderDetail(null);
            window.adminShowNotification && window.adminShowNotification('error', 'Assets konnten nicht geladen werden.');
        });
    }

    function normalizeCollection(data) {
        if (!Array.isArray(data)) {
            return [];
        }
        return data;
    }

    function renderGrid() {
        if (!grid) {
            return;
        }

        if (state.isLoading) {
            grid.innerHTML = '<p class="col-span-full rounded-2xl border border-dashed border-border bg-input-background/70 px-5 py-6 text-sm text-muted-foreground">Lade Assets...</p>';
            return;
        }

        if (state.assets.length === 0) {
            grid.innerHTML = '<p class="col-span-full rounded-2xl border border-dashed border-border bg-input-background/70 px-5 py-6 text-sm text-muted-foreground">Noch keine Assets vorhanden.</p>';
            return;
        }

        grid.innerHTML = state.assets.map(function (asset) {
            var id = parseInt(String(asset.id || 0), 10) || 0;
            var selectedClass = state.selectedId === id ? ' is-selected' : '';
            var isActive = !!Number(asset.is_active || 0);
            var activeLabel = isActive ? 'Aktiv' : 'Inaktiv';
            var nextActive = isActive ? '0' : '1';
            var altText = trim(asset.alt_text) !== '' ? trim(asset.alt_text) : 'Ohne Alt-Text';
            var fileLabel = trim(asset.original_filename) !== '' ? trim(asset.original_filename) : trim(asset.filename);
            var badgeClasses = isActive
                ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300'
                : 'border-border bg-input-background text-muted-foreground';

            return '' +
                '<article class="overflow-hidden rounded-2xl border ' + (state.selectedId === id ? 'border-primary/45 ring-2 ring-primary/20' : 'border-border') + ' bg-card shadow-[0_14px_30px_color-mix(in_srgb,var(--background)_28%,transparent)] transition hover:-translate-y-0.5 hover:border-primary/35 hover:shadow-[0_18px_36px_color-mix(in_srgb,var(--background)_34%,transparent)]' + selectedClass + '">' +
                '  <button type="button" class="group block w-full overflow-hidden bg-input-background" data-select-id="' + id + '">' +
                '    <img class="aspect-square w-full object-cover transition duration-300 group-hover:scale-[1.02]" src="' + escapeHtml(assetImageUrl(asset)) + '" alt="' + escapeHtml(altText) + '" loading="lazy" />' +
                '  </button>' +
                '  <div class="space-y-2.5 p-3.5">' +
                '    <p class="truncate text-sm font-semibold text-foreground" title="' + escapeHtml(fileLabel) + '">' + escapeHtml(fileLabel) + '</p>' +
                '    <p class="line-clamp-2 min-h-[2.5rem] text-sm leading-6 text-muted-foreground" title="' + escapeHtml(altText) + '">' + escapeHtml(altText) + '</p>' +
                '    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ' + badgeClasses + '">' + activeLabel + '</span>' +
                '    <div class="flex flex-wrap gap-2 pt-1">' +
                '      <button type="button" class="inline-flex items-center justify-center rounded-xl border border-border bg-input-background px-3 py-2 text-xs font-medium text-foreground transition hover:border-primary/40 hover:bg-background/40 focus:outline-none focus:ring-2 focus:ring-primary/20" data-select-id="' + id + '">Details</button>' +
                '      <button type="button" class="inline-flex items-center justify-center rounded-xl border border-border bg-input-background px-3 py-2 text-xs font-medium text-foreground transition hover:border-primary/40 hover:bg-background/40 focus:outline-none focus:ring-2 focus:ring-primary/20" data-toggle-id="' + id + '" data-next-active="' + nextActive + '">' + (isActive ? 'Deaktivieren' : 'Aktivieren') + '</button>' +
                '    </div>' +
                '  </div>' +
                '</article>';
        }).join('');
    }

    function selectAsset(id) {
        if (!Number.isFinite(id) || id <= 0) {
            return;
        }

        state.selectedId = id;
        renderGrid();
        renderDetail(getSelectedAsset());
    }

    function getSelectedAsset() {
        if (state.selectedId === null) {
            return null;
        }

        for (var i = 0; i < state.assets.length; i += 1) {
            var id = parseInt(String(state.assets[i].id || 0), 10) || 0;
            if (id === state.selectedId) {
                return state.assets[i];
            }
        }

        return null;
    }

    function renderDetail(asset) {
        if (!detailContent) {
            return;
        }

        if (!asset) {
            detailContent.innerHTML = '<div class="flex min-h-[420px] items-center justify-center rounded-2xl border border-dashed border-border bg-input-background/70 p-8 text-sm text-muted-foreground">Wählen Sie ein Asset aus dem Grid.</div>';
            return;
        }

        var assetId = parseInt(String(asset.id || 0), 10) || 0;
        var isActive = !!Number(asset.is_active || 0);
        var fileLabel = trim(asset.original_filename) !== '' ? trim(asset.original_filename) : trim(asset.filename);
        var mime = trim(asset.mime_type) !== '' ? trim(asset.mime_type) : '-';
        var sizeKb = Number(asset.file_size || 0) > 0 ? Math.round(Number(asset.file_size || 0) / 1024) + ' KB' : '-';
        var dim = (asset.width && asset.height) ? String(asset.width) + ' x ' + String(asset.height) : '-';

        detailContent.innerHTML = '' +
            '<div class="space-y-5 rounded-2xl border border-border bg-card p-5 shadow-[0_18px_40px_color-mix(in_srgb,var(--background)_28%,transparent)]">' +
            '  <img class="w-full rounded-2xl border border-border object-cover shadow-[0_18px_40px_color-mix(in_srgb,var(--background)_28%,transparent)]" src="' + escapeHtml(assetImageUrl(asset)) + '" alt="' + escapeHtml(trim(asset.alt_text) || 'Vorschau') + '" />' +
            '  <div class="grid gap-2 text-sm text-muted-foreground">' +
            '    <p><strong class="text-foreground">Datei:</strong> ' + escapeHtml(fileLabel) + '</p>' +
            '    <p><strong class="text-foreground">Typ:</strong> ' + escapeHtml(mime) + '</p>' +
            '    <p><strong class="text-foreground">Grösse:</strong> ' + escapeHtml(sizeKb) + '</p>' +
            '    <p><strong class="text-foreground">Dimension:</strong> ' + escapeHtml(dim) + '</p>' +
            '  </div>' +
            '  <form id="adminImagesDetailForm" class="space-y-4" data-asset-id="' + assetId + '">' +
            '    <label class="block space-y-2 text-sm font-medium text-foreground">' +
            '      <span>Alt-Text</span>' +
            '      <input class="block w-full rounded-xl border border-border bg-input-background px-4 py-3 text-sm text-foreground outline-none transition placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20" type="text" name="alt_text" maxlength="255" value="' + escapeHtml(trim(asset.alt_text)) + '" />' +
            '    </label>' +
            '    <label class="flex items-center gap-3 rounded-xl border border-border bg-input-background/70 px-4 py-3 text-sm text-foreground">' +
            '      <input class="h-4 w-4 rounded border-border bg-input-background text-primary focus:ring-primary/30" type="checkbox" name="is_active" ' + (isActive ? 'checked' : '') + ' />' +
            '      <span>Aktiv</span>' +
            '    </label>' +
            '    <div class="flex flex-wrap gap-2 pt-1">' +
            '      <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground shadow-lg shadow-primary/20 transition hover:-translate-y-0.5 hover:shadow-primary/30 focus:outline-none focus:ring-2 focus:ring-primary/30">Speichern</button>' +
            '      <button type="button" class="inline-flex items-center justify-center rounded-xl border border-border bg-input-background px-4 py-3 text-sm font-medium text-foreground transition hover:border-primary/40 hover:bg-background/40 focus:outline-none focus:ring-2 focus:ring-primary/20" data-assign-id="' + assetId + '">Seite zuweisen</button>' +
            '      <button type="button" class="inline-flex items-center justify-center rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm font-medium text-rose-200 transition hover:bg-rose-500/20 focus:outline-none focus:ring-2 focus:ring-rose-400/20" data-delete-id="' + assetId + '">Löschen</button>' +
            '  </div>' +
            '</form>';
    }

    function updateAsset(assetId, payload) {
        return fetchJson(apiPath('asset_update', assetId), {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload || {})
        }).then(function (result) {
            if (!result.ok) {
                var message = buildApiErrorMessage(result, 'Änderung konnte nicht gespeichert werden.');
                window.adminShowNotification && window.adminShowNotification('error', message);
                return false;
            }

            return true;
        }).catch(function () {
            window.adminShowNotification && window.adminShowNotification('error', 'Änderung konnte nicht gespeichert werden.');
            return false;
        });
    }

    function destroyAsset(assetId) {
        if (!window.confirm('Asset wirklich löschen?')) {
            return;
        }

        fetchJson(apiPath('asset_delete', assetId), {
            method: 'DELETE'
        }).then(function (result) {
            if (!result.ok) {
                var message = buildApiErrorMessage(result, 'Asset konnte nicht gelöscht werden.');
                window.adminShowNotification && window.adminShowNotification('error', message);
                return;
            }

            window.adminShowNotification && window.adminShowNotification('success', 'Asset wurde gelöscht.');
            state.selectedId = null;
            loadAssets();
        }).catch(function () {
            window.adminShowNotification && window.adminShowNotification('error', 'Asset konnte nicht gelöscht werden.');
        });
    }

    function openAssignModal(assetId) {
        if (!assignTemplate || !window.adminOpenModal) {
            return;
        }

        window.adminOpenModal('Asset einer Seite zuweisen', assignTemplate.innerHTML, {
            type: 'form',
            buttons: [
                {
                    label: 'Abbrechen',
                    variant: 'secondary',
                    onClick: function () {
                        window.adminCloseModal && window.adminCloseModal();
                    }
                },
                {
                    label: 'Zuweisen',
                    variant: 'primary',
                    onClick: function () {
                        submitAssignForm();
                    }
                }
            ]
        });

        var hiddenAssetId = document.getElementById('adminImagesAssignAssetId');
        if (hiddenAssetId) {
            hiddenAssetId.value = String(assetId);
        }

        setupAssignSelectors();
    }

    function setupAssignSelectors() {
        var slotsConfig = Array.isArray(cfg.gallery_slots) ? cfg.gallery_slots : [];
        var pageSelect = document.getElementById('adminImagesAssignPage');
        var sectionSelect = document.getElementById('adminImagesAssignSection');
        var slotSelect = document.getElementById('adminImagesAssignSlot');

        if (!pageSelect || !sectionSelect || !slotSelect) {
            return;
        }

        pageSelect.innerHTML = slotsConfig.map(function (page) {
            return '<option value="' + escapeHtml(String(page.page_key || '')) + '">' + escapeHtml(String(page.label || page.page_key || 'Seite')) + '</option>';
        }).join('');

        function renderSections() {
            var pageKey = String(pageSelect.value || '');
            var pageCfg = findPageConfig(slotsConfig, pageKey);
            var sections = pageCfg && Array.isArray(pageCfg.sections) ? pageCfg.sections : [];

            sectionSelect.innerHTML = sections.map(function (section) {
                return '<option value="' + escapeHtml(String(section.section_key || 'default')) + '">' + escapeHtml(String(section.label || section.section_key || 'Bereich')) + '</option>';
            }).join('');

            renderSlots();
        }

        function renderSlots() {
            var pageKey = String(pageSelect.value || '');
            var sectionKey = String(sectionSelect.value || 'default');
            var sectionCfg = findSectionConfig(slotsConfig, pageKey, sectionKey);
            var slots = sectionCfg && Array.isArray(sectionCfg.slots) ? sectionCfg.slots : [];

            slotSelect.innerHTML = slots.map(function (slot) {
                return '<option value="' + escapeHtml(String(slot || 'main')) + '">' + escapeHtml(String(slot || 'main')) + '</option>';
            }).join('');
        }

        pageSelect.addEventListener('change', renderSections);
        sectionSelect.addEventListener('change', renderSlots);

        renderSections();
    }

    function submitAssignForm() {
        var form = document.getElementById('adminImagesAssignForm');
        if (!form) {
            return;
        }

        var pageKey = String(readInputValue('adminImagesAssignPage') || '');
        var sectionKey = String(readInputValue('adminImagesAssignSection') || 'default');
        var slotKey = String(readInputValue('adminImagesAssignSlot') || 'main');
        var assetId = parseInt(String(readInputValue('adminImagesAssignAssetId') || '0'), 10) || 0;

        if (pageKey === '' || slotKey === '' || assetId <= 0) {
            setAssignHint('Bitte Seite, Bereich und Slot wählen.');
            return;
        }

        fetchJson(apiPath('page_assignments_store', null, pageKey), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                section_key: sectionKey,
                slot_key: slotKey,
                asset_id: assetId
            })
        }).then(function (result) {
            if (!result.ok) {
                var message = buildApiErrorMessage(result, 'Zuweisung fehlgeschlagen.');
                setAssignHint(message);
                window.adminShowNotification && window.adminShowNotification('error', message);
                return;
            }

            window.adminShowNotification && window.adminShowNotification('success', 'Asset wurde zugewiesen.');
            window.adminCloseModal && window.adminCloseModal();
        }).catch(function () {
            var message = 'Zuweisung fehlgeschlagen.';
            setAssignHint(message);
            window.adminShowNotification && window.adminShowNotification('error', message);
        });
    }

    function setAssignHint(message) {
        var hint = document.getElementById('adminImagesAssignHint');
        if (hint) {
            hint.textContent = String(message || '');
        }
    }

    function readInputValue(id) {
        var el = document.getElementById(id);
        return el ? el.value : '';
    }

    function findPageConfig(slotsConfig, pageKey) {
        for (var i = 0; i < slotsConfig.length; i += 1) {
            if (String(slotsConfig[i].page_key || '') === pageKey) {
                return slotsConfig[i];
            }
        }
        return null;
    }

    function findSectionConfig(slotsConfig, pageKey, sectionKey) {
        var pageCfg = findPageConfig(slotsConfig, pageKey);
        var sections = pageCfg && Array.isArray(pageCfg.sections) ? pageCfg.sections : [];

        for (var i = 0; i < sections.length; i += 1) {
            if (String(sections[i].section_key || 'default') === sectionKey) {
                return sections[i];
            }
        }

        return null;
    }

    function apiPath(key, id, pageKey) {
        var api = cfg.api || {};
        var path = String(api[key] || '');
        if (path === '') {
            return '';
        }

        if (typeof id !== 'undefined' && id !== null) {
            path = path.replace('{id}', String(id));
        }

        if (typeof pageKey !== 'undefined' && pageKey !== null) {
            path = path.replace('{page_key}', encodeURIComponent(String(pageKey)));
        }

        return path;
    }

    function fetchJson(url, options) {
        options = options || {};
        options.credentials = 'include';
        options.headers = options.headers || {};

        if (!options.headers.Accept) {
            options.headers.Accept = 'application/json';
        }

        return fetch(url, options).then(function (res) {
            return res.json().catch(function () {
                return {};
            }).then(function (json) {
                return {
                    ok: res.ok,
                    status: res.status,
                    json: json
                };
            });
        });
    }

    function buildApiErrorMessage(result, fallback) {
        var json = result && result.json ? result.json : {};
        var details = [];
        var errors = json && typeof json === 'object' ? json.errors : null;

        if (errors && typeof errors === 'object') {
            Object.keys(errors).forEach(function (key) {
                var values = errors[key];
                if (Array.isArray(values) && values.length > 0) {
                    details.push(String(values[0]));
                } else if (typeof values === 'string') {
                    details.push(values);
                }
            });
        }

        if (details.length > 0) {
            return fallback + ' ' + details.slice(0, 2).join(' ');
        }

        var message = trim(json && json.message ? json.message : '');
        if (message !== '') {
            return fallback + ' ' + message;
        }

        return fallback;
    }

    function formatBytes(bytes) {
        var size = Number(bytes || 0);
        if (!Number.isFinite(size) || size <= 0) {
            return '0 B';
        }
        if (size >= 1024 * 1024 * 1024) {
            return trimTrailingZeros(size / (1024 * 1024 * 1024)) + ' GB';
        }
        if (size >= 1024 * 1024) {
            return trimTrailingZeros(size / (1024 * 1024)) + ' MB';
        }
        if (size >= 1024) {
            return trimTrailingZeros(size / 1024) + ' KB';
        }
        return Math.round(size) + ' B';
    }

    function trimTrailingZeros(value) {
        return String(Number(value).toFixed(2)).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
    }

    function assetImageUrl(asset) {
        var filename = trim(asset && asset.filename);
        if (filename === '') {
            return placeholderSvg();
        }

        if (filename.indexOf('/storage/') === 0) {
            return filename;
        }

        if (filename.indexOf('/') !== -1) {
            return '/storage/media/' + filename;
        }

        var createdAt = trim(asset && asset.created_at);
        if (createdAt !== '') {
            var date = new Date(createdAt.replace(' ', 'T'));
            if (!Number.isNaN(date.getTime())) {
                var y = String(date.getFullYear());
                var m = String(date.getMonth() + 1).padStart(2, '0');
                return '/storage/media/' + y + '/' + m + '/' + filename;
            }
        }

        return '/storage/media/' + filename;
    }

    function placeholderSvg() {
        return 'data:image/svg+xml;utf8,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 240">' +
            '<rect width="320" height="240" fill="#f2eee5"/>' +
            '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#7a7067" font-family="sans-serif" font-size="16">Kein Bild</text>' +
            '</svg>'
        );
    }

    function trim(value) {
        return String(value || '').trim();
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}());
