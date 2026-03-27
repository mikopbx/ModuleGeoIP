"use strict";

/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

/* global globalRootUrl, globalTranslate, Form, Config, PbxApi, SemanticLocalization */

var ModuleGeoIP = {
  $formObj: $('#module-geoip-form'),
  $ipsetWarning: $('#geoip-ipset-warning'),
  $toggleAll: $('#geoip-toggle-all'),
  $updateNowBtn: $('#geoip-update-now'),
  dataTable: null,
  countries: [],
  suppressToggleAll: false,
  /**
   * Initialize module UI.
   */
  initialize: function initialize() {
    ModuleGeoIP.loadCountries();

    // Header toggle: block/unblock all countries
    ModuleGeoIP.$toggleAll.checkbox({
      onChange: function onChange() {
        if (ModuleGeoIP.suppressToggleAll) {
          return;
        }
        var allowAll = ModuleGeoIP.$toggleAll.checkbox('is checked');
        ModuleGeoIP.countries.forEach(function (c) {
          c.blocked = !allowAll;
        });
        ModuleGeoIP.dataTable.clear().rows.add(ModuleGeoIP.countries).draw();
        Form.dataChanged();
      }
    });
    ModuleGeoIP.$updateNowBtn.on('click', function (e) {
      e.preventDefault();
      ModuleGeoIP.updateNow();
    });
    ModuleGeoIP.initializeForm();
  },
  /**
   * Load country list via REST API and init DataTable.
   */
  loadCountries: function loadCountries() {
    $.api({
      url: "".concat(Config.pbxUrl, "/pbxcore/api/modules/ModuleGeoIP/getList?lang=").concat(globalWebAdminLanguage),
      on: 'now',
      method: 'GET',
      successTest: PbxApi.successTest,
      onSuccess: function onSuccess(response) {
        ModuleGeoIP.countries = response.data.countries || [];
        ModuleGeoIP.savedStatusFilter = response.data.statusFilter || 'all';
        ModuleGeoIP.initDataTable();
        ModuleGeoIP.syncToggleAll();
      },
      onFailure: function onFailure() {
        $('#geoip-countries-table tbody').html("<tr><td colspan=\"2\" class=\"center aligned\">".concat(globalTranslate.mod_GeoIP_LoadError, "</td></tr>"));
      }
    });
  },
  /**
   * Initialize DataTable with country data.
   */
  initDataTable: function initDataTable() {
    ModuleGeoIP.dataTable = $('#geoip-countries-table').DataTable({
      data: ModuleGeoIP.countries,
      paging: true,
      pageLength: 50,
      deferRender: true,
      searching: true,
      ordering: true,
      lengthChange: false,
      order: [[0, 'asc']],
      language: SemanticLocalization.dataTableLocalisation,
      columns: [{
        data: null,
        render: function render(data) {
          return "<i class=\"".concat(data.flag, " flag\"></i> ").concat(data.name, " <small class=\"ui grey text\">").concat(data.code, "</small>");
        }
      }, {
        data: null,
        orderable: false,
        searchable: false,
        className: 'center aligned',
        render: function render(data) {
          var checked = data.blocked ? '' : 'checked';
          var statusText = data.blocked ? globalTranslate.mod_GeoIP_Blocked : globalTranslate.mod_GeoIP_Allowed;
          var statusColor = data.blocked ? 'red' : 'green';
          return "<div class=\"ui toggle checkbox country-checkbox\" data-code=\"".concat(data.code, "\">") + "<input type=\"checkbox\" ".concat(checked, ">") + "<label><span class=\"ui ".concat(statusColor, " text\">").concat(statusText, "</span></label>") + "</div>";
        }
      }],
      createdRow: function createdRow(row, data) {
        $(row).attr('data-code', data.code);
        if (data.blocked) {
          $(row).addClass('blocked');
        }
      },
      drawCallback: function drawCallback() {
        // Init Fomantic checkboxes after each page draw
        $('.country-checkbox').checkbox({
          onChange: function onChange() {
            var $cb = $(this).closest('.checkbox');
            var $row = $(this).closest('tr');
            var isAllowed = $cb.checkbox('is checked');

            // Update data in source array
            var rowData = ModuleGeoIP.dataTable.row($row).data();
            if (rowData) {
              rowData.blocked = !isAllowed;
            }

            // Update row visual
            if (isAllowed) {
              $row.removeClass('blocked');
              $cb.find('label span').removeClass('red').addClass('green').text(globalTranslate.mod_GeoIP_Allowed);
            } else {
              $row.addClass('blocked');
              $cb.find('label span').removeClass('green').addClass('red').text(globalTranslate.mod_GeoIP_Blocked);
            }

            // Sync header toggle and enable save
            ModuleGeoIP.syncToggleAll();
            Form.dataChanged();
          }
        });
      }
    });

    // Move update button into DataTable toolbar (left column)
    var $filterRow = $('#geoip-countries-table_wrapper .row:first');
    if ($filterRow.length) {
      $filterRow.find('.column:first').append(ModuleGeoIP.$updateNowBtn);

      // Add status filter dropdown next to search
      var filterHtml = "<select id=\"geoip-status-filter\" class=\"ui compact dropdown\">\n                <option value=\"all\">".concat(globalTranslate.mod_GeoIP_FilterAll, "</option>\n                <option value=\"allowed\">").concat(globalTranslate.mod_GeoIP_FilterAllowed, "</option>\n                <option value=\"blocked\">").concat(globalTranslate.mod_GeoIP_FilterBlocked, "</option>\n            </select>");
      $filterRow.find('.column:last').prepend(filterHtml);

      // Register DataTable filter BEFORE dropdown init (so set selected triggers filtering)
      $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        if (settings.nTable.id !== 'geoip-countries-table') {
          return true;
        }
        var filter = ModuleGeoIP.savedStatusFilter || 'all';
        if (filter === 'all') {
          return true;
        }
        var rowData = ModuleGeoIP.dataTable.row(dataIndex).data();
        if (!rowData) {
          return true;
        }
        if (filter === 'allowed') {
          return !rowData.blocked;
        }
        return rowData.blocked;
      });
      $('#geoip-status-filter').dropdown({
        onChange: function onChange(value) {
          ModuleGeoIP.savedStatusFilter = value;
          ModuleGeoIP.dataTable.draw();
          ModuleGeoIP.saveStatusFilter(value);
        }
      });

      // Restore saved filter
      if (ModuleGeoIP.savedStatusFilter && ModuleGeoIP.savedStatusFilter !== 'all') {
        $('#geoip-status-filter').dropdown('set selected', ModuleGeoIP.savedStatusFilter);
      } else {
        ModuleGeoIP.dataTable.draw();
      }
    }

    // Load status to set tooltip
    ModuleGeoIP.loadStatus();
  },
  /**
   * Sync header toggle checkbox with current data state.
   */
  syncToggleAll: function syncToggleAll() {
    var totalBlocked = ModuleGeoIP.countries.filter(function (c) {
      return c.blocked;
    }).length;
    ModuleGeoIP.suppressToggleAll = true;
    if (totalBlocked === 0) {
      ModuleGeoIP.$toggleAll.checkbox('set checked');
    } else {
      ModuleGeoIP.$toggleAll.checkbox('set unchecked');
    }
    ModuleGeoIP.suppressToggleAll = false;
  },
  /**
   * Load module status and update tooltip on update button.
   */
  loadStatus: function loadStatus() {
    $.api({
      url: "".concat(Config.pbxUrl, "/pbxcore/api/modules/ModuleGeoIP/getStatus"),
      on: 'now',
      method: 'GET',
      successTest: PbxApi.successTest,
      onSuccess: function onSuccess(response) {
        var data = response.data;
        var tooltip;
        if (data.lastUpdate) {
          tooltip = "".concat(globalTranslate.mod_GeoIP_LastUpdate, ": ").concat(data.lastUpdate);
        } else {
          tooltip = globalTranslate.mod_GeoIP_NeverUpdated;
        }
        ModuleGeoIP.$updateNowBtn.attr('data-tooltip', tooltip);
        if (data.updateRequested || data.progress >= 0) {
          // Update in progress or requested — show spinner and poll
          ModuleGeoIP.$updateNowBtn.addClass('disabled').find('i').removeClass('sync').addClass('spinner loading');
          if (data.progress >= 0) {
            ModuleGeoIP.wasUpdating = true;
            ModuleGeoIP.$updateNowBtn.append(" <span class=\"progress-text\">".concat(data.progress, "%</span>"));
          } else {
            ModuleGeoIP.$updateNowBtn.find('.progress-text').remove();
            ModuleGeoIP.$updateNowBtn.append(" <span class=\"progress-text\">".concat(globalTranslate.mod_GeoIP_Preparing, "</span>"));
          }
          ModuleGeoIP.pollUpdateStatus(0);
        } else {
          // Normal state
          ModuleGeoIP.$updateNowBtn.removeClass('disabled').find('i').removeClass('spinner loading').addClass('sync');
          ModuleGeoIP.$updateNowBtn.find('.progress-text').remove();
        }
        if (!data.ipsetAvailable) {
          ModuleGeoIP.$ipsetWarning.show();
        }
      }
    });
  },
  wasUpdating: false,
  /**
   * Trigger immediate CIDR update.
   * Shows loading state and polls status until lastUpdate changes.
   */
  updateNow: function updateNow() {
    ModuleGeoIP.wasUpdating = false;
    ModuleGeoIP.$updateNowBtn.addClass('disabled').find('i').removeClass('sync').addClass('spinner loading');
    $.api({
      url: "".concat(Config.pbxUrl, "/pbxcore/api/modules/ModuleGeoIP/updateNow"),
      on: 'now',
      method: 'POST',
      successTest: PbxApi.successTest,
      onSuccess: function onSuccess() {
        // Poll status every 5s until lastUpdate changes
        ModuleGeoIP.pollUpdateStatus(0);
      },
      onFailure: function onFailure() {
        ModuleGeoIP.$updateNowBtn.removeClass('disabled').find('i').removeClass('spinner loading').addClass('sync');
        UserMessage.showError(globalTranslate.mod_GeoIP_UpdateError);
      }
    });
  },
  /**
   * Poll getStatus until lastUpdate changes or timeout.
   */
  pollUpdateStatus: function pollUpdateStatus(attempt) {
    if (attempt > 60) {
      // Timeout after 5 minutes
      ModuleGeoIP.$updateNowBtn.removeClass('disabled').find('i').removeClass('spinner loading').addClass('sync');
      return;
    }
    setTimeout(function () {
      $.api({
        url: "".concat(Config.pbxUrl, "/pbxcore/api/modules/ModuleGeoIP/getStatus"),
        on: 'now',
        method: 'GET',
        successTest: PbxApi.successTest,
        onSuccess: function onSuccess(response) {
          var data = response.data;
          var newTooltip;
          if (data.lastUpdate) {
            newTooltip = "".concat(globalTranslate.mod_GeoIP_LastUpdate, ": ").concat(data.lastUpdate);
          } else {
            newTooltip = globalTranslate.mod_GeoIP_NeverUpdated;
          }
          if (data.progress >= 0) {
            // Update in progress — show percentage
            ModuleGeoIP.wasUpdating = true;
            var $pt = ModuleGeoIP.$updateNowBtn.find('.progress-text');
            if (!$pt.length) {
              ModuleGeoIP.$updateNowBtn.append(' <span class="progress-text"></span>');
              $pt = ModuleGeoIP.$updateNowBtn.find('.progress-text');
            }
            $pt.text("".concat(data.progress, "%"));
            ModuleGeoIP.pollUpdateStatus(attempt + 1);
          } else if (ModuleGeoIP.wasUpdating && data.lastUpdate) {
            // Progress gone + lastUpdate present = completed
            ModuleGeoIP.wasUpdating = false;
            var tooltip = "".concat(globalTranslate.mod_GeoIP_LastUpdate, ": ").concat(data.lastUpdate);
            ModuleGeoIP.$updateNowBtn.removeClass('disabled').attr('data-tooltip', tooltip).find('i').removeClass('spinner loading').addClass('sync');
            ModuleGeoIP.$updateNowBtn.find('.progress-text').remove();
            UserMessage.showInformation(globalTranslate.mod_GeoIP_UpdateSuccess);
          } else {
            // Waiting for worker to start
            ModuleGeoIP.pollUpdateStatus(attempt + 1);
          }
        },
        onFailure: function onFailure() {
          ModuleGeoIP.pollUpdateStatus(attempt + 1);
        }
      });
    }, 5000);
  },
  /**
   * Save status filter preference to server.
   */
  saveStatusFilter: function saveStatusFilter(value) {
    $.api({
      url: "".concat(Config.pbxUrl, "/pbxcore/api/modules/ModuleGeoIP/save"),
      on: 'now',
      method: 'POST',
      data: JSON.stringify({
        statusFilter: value
      }),
      beforeSend: function beforeSend(settings) {
        settings.contentType = 'application/json';
        return settings;
      }
    });
  },
  /**
   * Collect blocked country codes from data.
   */
  getBlockedCodes: function getBlockedCodes() {
    var blocked = [];
    ModuleGeoIP.countries.forEach(function (c) {
      if (c.blocked) {
        blocked.push(c.code);
      }
    });
    return blocked;
  },
  /**
   * Prepare form data before send.
   */
  cbBeforeSendForm: function cbBeforeSendForm(settings) {
    var result = settings;
    result.data = ModuleGeoIP.$formObj.form('get values');
    result.data.blocked = ModuleGeoIP.getBlockedCodes();
    return result;
  },
  /**
   * After form save, apply configuration.
   */
  cbAfterSendForm: function cbAfterSendForm() {
    $.api({
      url: "".concat(Config.pbxUrl, "/pbxcore/api/modules/ModuleGeoIP/save"),
      on: 'now',
      method: 'POST',
      data: JSON.stringify({
        blocked: ModuleGeoIP.getBlockedCodes(),
        statusFilter: ModuleGeoIP.savedStatusFilter || 'all'
      }),
      beforeSend: function beforeSend(settings) {
        settings.contentType = 'application/json';
        return settings;
      },
      successTest: PbxApi.successTest,
      onSuccess: function onSuccess() {
        ModuleGeoIP.loadStatus();
      }
    });
  },
  /**
   * Initialize Semantic UI form.
   */
  initializeForm: function initializeForm() {
    Form.$formObj = ModuleGeoIP.$formObj;
    Form.url = "".concat(globalRootUrl, "ModuleGeoIP/save");
    Form.validateRules = ModuleGeoIP.validateRules;
    Form.cbBeforeSendForm = ModuleGeoIP.cbBeforeSendForm;
    Form.cbAfterSendForm = ModuleGeoIP.cbAfterSendForm;
    Form.initialize();
  },
  validateRules: {}
};
$(document).ready(function () {
  ModuleGeoIP.initialize();
});
