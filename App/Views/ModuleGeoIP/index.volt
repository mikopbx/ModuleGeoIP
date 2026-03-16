<form class="ui large grey segment form" id="module-geoip-form">

    {# Ipset unavailable warning #}
    <div class="ui warning message" id="geoip-ipset-warning" style="display:none;">
        <div class="header">{{ t._('mod_GeoIP_IpsetUnavailableTitle') }}</div>
        <p>{{ t._('mod_GeoIP_IpsetUnavailable') }}</p>
    </div>

    {# Update button - will be moved into DataTable toolbar by JS #}
    <button class="ui basic blue button" id="geoip-update-now" type="button"
            data-tooltip="{{ t._('mod_GeoIP_NeverUpdated') }}" data-position="top left">
        <i class="sync icon"></i>
        {{ t._('mod_GeoIP_UpdateNow') }}
    </button>

    {# Countries table - DataTables #}
    <table class="ui very compact selectable unstackable table" id="geoip-countries-table">
        <thead>
            <tr>
                <th>{{ t._('mod_GeoIP_CountryName') }}</th>
                <th class="three wide center aligned">
                    <div class="ui toggle checkbox" id="geoip-toggle-all">
                        <input type="checkbox" checked>
                        <label>{{ t._('mod_GeoIP_Status') }}</label>
                    </div>
                </th>
            </tr>
        </thead>
        <tbody>
        </tbody>
    </table>

    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>
