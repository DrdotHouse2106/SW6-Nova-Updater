import template from './sw-extension-card-base.html.twig';
import './sw-extension-card-base.scss';
import DhGithubUpdaterApiService from '../../service/dh-github-updater-api.service';

// Erlaubt „https://github.com/owner/repo“ (optional mit .git oder / am Ende) und „owner/repo“
const REPOSITORY_PATTERN = /^(?:https:\/\/github\.com\/)?[A-Za-z0-9-]{1,39}\/[A-Za-z0-9._-]{1,100}?(?:\.git)?\/?$/;

const STATUS_ICONS = {
    ok: 'regular-checkmark-xs',
    warning: 'regular-exclamation-triangle',
    error: 'regular-times-xs',
    skipped: 'regular-minus-xs',
};

Shopware.Component.override('sw-extension-card-base', {
    template,

    inject: ['acl', 'dhGithubUpdaterApiService', 'repositoryFactory'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            dhGithub: {
                showChangelog: false,
                loaded: false,
                repository: null,
                installedRef: null,
                editRepository: '',
                showModal: false,
                isBusy: false,
                isTesting: false,
                testResult: null,
                updates: null,
                selectedKey: null,
                checkedAt: null,
                progress: '',
            },
        };
    },

    computed: {
        /** Grundvoraussetzung: Plugin (keine App) und passende Rechte */
        dhGithubAllowed() {
            return this.extension?.type === 'plugin'
                && this.acl.can('system.plugin_maintain')
                && this.acl.can('system.plugin_upload')
                && !this.extensionManagementDisabled;
        },

        /** Verknüpfen wird für alle Plugins angeboten – auch für Plugins, die sonst aus dem Store kämen */
        dhGithubCanLink() {
            return this.dhGithubAllowed && this.isInstalled;
        },

        /** Kontextmenü auch dann anzeigen, wenn Shopware selbst keinen Eintrag hätte */
        showContextMenu() {
            return this.$super('showContextMenu')
                || (this.dhGithub.loaded && (this.dhGithubCanLink || !!this.dhGithub.repository));
        },

        dhGithubEditValid() {
            return REPOSITORY_PATTERN.test(this.dhGithub.editRepository.trim());
        },

        dhGithubEditError() {
            const value = this.dhGithub.editRepository.trim();
            if (value === '' || this.dhGithubEditValid) {
                return null;
            }

            return { detail: this.$tc('dh-github-updater.linkModal.invalid') };
        },

        dhGithubTestChecks() {
            return (this.dhGithub.testResult?.checks || []).map((check) => ({
                ...check,
                icon: STATUS_ICONS[check.status] || STATUS_ICONS.skipped,
                label: this.$tc(`dh-github-updater.test.checks.${check.key}`),
            }));
        },

        dhGithubUpdateOptions() {
            return (this.dhGithub.updates || []).map((update) => ({
                value: `${update.type}:${update.ref}`,
                label: update.label,
            }));
        },

        dhGithubCheckedTitle() {
            if (!this.dhGithub.checkedAt) {
                return '';
            }

            return this.$t('dh-github-updater.card.lastChecked', {
                date: new Date(this.dhGithub.checkedAt * 1000).toLocaleString(),
            });
        },

        dhGithubChangelogEntries() {
            const update = this.dhGithubSelectedUpdate;

            return [{
                pluginName: this.extension.name,
                label: this.extension.label || this.extension.name,
                from: this.extension.version,
                to: update ? (update.version || update.ref) : null,
            }];
        },

        dhGithubSelectedUpdate() {
            return (this.dhGithub.updates || [])
                .find((update) => `${update.type}:${update.ref}` === this.dhGithub.selectedKey) || null;
        },
    },

    created() {
        this.dhGithubLoadSource();
        this.dhGithubOnStatusRefreshed = () => this.dhGithubLoadSource(true, true);
        window.addEventListener(DhGithubUpdaterApiService.STATUS_EVENT, this.dhGithubOnStatusRefreshed);
    },

    beforeUnmount() {
        window.removeEventListener(DhGithubUpdaterApiService.STATUS_EVENT, this.dhGithubOnStatusRefreshed);
    },

    methods: {
        async dhGithubLoadSource(force = false, resetUpdates = false) {
            if (!this.dhGithubAllowed) {
                return;
            }

            try {
                const result = await this.dhGithubUpdaterApiService.getSources(force);
                const source = result.sources.find((entry) => entry.pluginName === this.extension.name);
                this.dhGithub.repository = source?.repository ?? null;
                this.dhGithub.installedRef = source?.installedRef ?? null;

                if (resetUpdates) {
                    this.dhGithub.updates = null;
                    this.dhGithub.selectedKey = null;
                }

                if (this.dhGithub.repository) {
                    this.dhGithubLoadUpdateStatus();
                }
            } catch {
                this.dhGithub.repository = null;
            } finally {
                this.dhGithub.loaded = true;
            }
        },

        /**
         * Ergebnis der automatischen Prüfung übernehmen – verfügbare Updates
         * erscheinen so ohne Klick direkt auf der Karte.
         */
        async dhGithubLoadUpdateStatus() {
            try {
                const status = await this.dhGithubUpdaterApiService.getUpdateStatus();
                const entry = status.plugins?.[this.extension.name];
                this.dhGithub.checkedAt = status.checkedAt;

                if (entry && !entry.error && this.dhGithub.updates === null && entry.updates.length > 0) {
                    this.dhGithub.updates = entry.updates;
                    this.dhGithub.selectedKey = this.dhGithubUpdateOptions[0]?.value ?? null;
                }
            } catch {
                // Automatische Prüfung ist optional – manuell prüfen bleibt möglich
            }
        },

        dhGithubOpenModal(runTest = false) {
            this.dhGithub.editRepository = this.dhGithub.repository ? `https://github.com/${this.dhGithub.repository}` : '';
            this.dhGithub.testResult = null;
            this.dhGithub.showModal = true;

            if (runTest === true && this.dhGithubEditValid) {
                this.dhGithubTest();
            }
        },

        dhGithubCloseModal() {
            if (this.dhGithub.isBusy) {
                return;
            }

            this.dhGithub.showModal = false;
            this.dhGithub.testResult = null;
        },

        async dhGithubTest() {
            if (!this.dhGithubEditValid) {
                return;
            }

            this.dhGithub.isTesting = true;
            this.dhGithub.testResult = null;

            try {
                this.dhGithub.testResult = await this.dhGithubUpdaterApiService.testConnection(
                    this.dhGithub.editRepository.trim(),
                    this.extension.name,
                );
            } catch (error) {
                this.dhGithubNotifyError(error);
            } finally {
                this.dhGithub.isTesting = false;
            }
        },

        async dhGithubSaveLink() {
            if (!this.dhGithubEditValid || this.dhGithub.isBusy) {
                return;
            }

            this.dhGithub.isBusy = true;

            try {
                const result = await this.dhGithubUpdaterApiService.link(this.extension.name, this.dhGithub.editRepository.trim());
                this.dhGithub.repository = result.repository;
                this.dhGithub.updates = null;
                this.dhGithub.showModal = false;
                this.createNotificationSuccess({
                    message: this.$t('dh-github-updater.card.linkSuccess', { name: this.extension.label || this.extension.name, repository: result.repository }),
                });
                await this.dhGithubLoadSource(true);
            } catch (error) {
                this.dhGithubNotifyError(error);
            } finally {
                this.dhGithub.isBusy = false;
            }
        },

        async dhGithubUnlink() {
            this.dhGithub.isBusy = true;

            try {
                await this.dhGithubUpdaterApiService.unlink(this.extension.name);
                this.dhGithub.repository = null;
                this.dhGithub.installedRef = null;
                this.dhGithub.updates = null;
                this.createNotificationInfo({
                    message: this.$t('dh-github-updater.card.unlinkSuccess', { name: this.extension.label || this.extension.name }),
                });
                await this.dhGithubLoadSource(true);
            } catch (error) {
                this.dhGithubNotifyError(error);
            } finally {
                this.dhGithub.isBusy = false;
            }
        },

        async dhGithubCheck() {
            this.dhGithub.isBusy = true;

            try {
                const result = await this.dhGithubUpdaterApiService.check(this.extension.name);
                this.dhGithub.updates = result.updates;
                this.dhGithub.checkedAt = Math.floor(Date.now() / 1000);
                this.dhGithub.selectedKey = this.dhGithubUpdateOptions[0]?.value ?? null;

                if (result.updates.length === 0 && !result.latestVersion) {
                    // Keine (kompatible) Version im Repository – meist fehlt ein Release/Tag
                    this.createNotificationWarning({
                        message: this.$t('dh-github-updater.card.noReleases', { name: this.extension.label || this.extension.name }),
                    });
                } else if (result.updates.length === 0) {
                    this.createNotificationInfo({
                        message: this.$t('dh-github-updater.card.upToDate', {
                            name: this.extension.label || this.extension.name,
                            version: result.latestVersion,
                        }),
                    });
                }
            } catch (error) {
                this.dhGithubNotifyError(error);
            } finally {
                this.dhGithub.isBusy = false;
            }
        },

        async dhGithubUpdate() {
            const update = this.dhGithubSelectedUpdate;
            if (!update) {
                return;
            }

            this.dhGithub.isBusy = true;

            try {
                const result = await this.dhGithubUpdaterApiService.update(this.extension.name, update.ref, update.type);

                // Der eigentliche Plugin-Update-Lebenszyklus (Migrationen etc.) läuft in einer neuen Anfrage
                // mit dem neuen Code – ohne Themes zu kompilieren; die Themes folgen danach Shop für Shop.
                const warnings = [];
                const label = this.extension.label || this.extension.name;
                if (result.installed) {
                    let themeJobs = [];
                    this.dhGithub.progress = this.$tc('dh-github-updater.progress.update');
                    try {
                        themeJobs = (await this.dhGithubUpdaterApiService.lifecycleUpdate(this.extension.name)).themeJobs || [];
                    } catch (error) {
                        // Update gescheitert, der vorherige Stand ist bereits wiederhergestellt
                        if (error?.response?.data?.restored) {
                            throw error;
                        }

                        // Läuft Shopware noch? Bis zu 5 Minuten nachsehen, bevor eine Warnung erscheint
                        this.dhGithub.progress = this.$tc('dh-github-updater.progress.waiting');
                        const done = await DhGithubUpdaterApiService.waitForPlugin(
                            this.repositoryFactory,
                            this.extension.name,
                            (plugin) => !plugin.upgradeVersion,
                            300000,
                        );
                        if (!done) {
                            warnings.push({
                                code: 'updateFailed',
                                params: {
                                    name: this.extension.name,
                                    error: DhGithubUpdaterApiService.getErrorMessage(error, this.$tc('dh-github-updater.general.unknownError')),
                                },
                            });
                        }
                    }

                    if (themeJobs.length > 0) {
                        const failed = await this.dhGithubUpdaterApiService.compileThemes(themeJobs, (done, total, job) => {
                            this.dhGithub.progress = job
                                ? this.$t('dh-github-updater.progress.themes', { done: done + 1, total, name: job.name })
                                : '';
                        });
                        if (failed.length > 0) {
                            warnings.push({ code: 'themeCompileFailed', params: { shops: failed.map((job) => job.name).join(', ') } });
                        }
                    }
                    this.dhGithub.progress = '';
                }

                this.createNotificationSuccess({
                    message: this.$t('dh-github-updater.card.updateSuccess', { name: label }),
                });
                warnings.unshift(...await this.dhGithubUpdaterApiService.confirmWarnings(result.warnings));
                DhGithubUpdaterApiService.rememberWarnings(label, warnings);
                if (update.type !== 'branch') {
                    DhGithubUpdaterApiService.rememberChangelog({
                        pluginName: this.extension.name,
                        label,
                        from: this.extension.version,
                        to: update.version || update.ref,
                    });
                }

                this.clearCacheAndReloadPage();
            } catch (error) {
                this.dhGithubNotifyError(error);
                this.dhGithub.isBusy = false;
                this.dhGithub.progress = '';
            }
        },

        dhGithubNotifyError(error) {
            this.createNotificationError({
                message: DhGithubUpdaterApiService.getErrorMessage(error, this.$tc('dh-github-updater.general.unknownError')),
            });
        },
    },
});
