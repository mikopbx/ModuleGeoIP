<form class="ui large grey segment form" id="module-geoip-form">

    {# Ipset unavailable warning #}
    <div class="ui warning message" id="geoip-ipset-warning" style="display:none;">
        <div class="header">{{ t._('mod_GeoIP_IpsetUnavailableTitle') }}</div>
        <p>{{ t._('mod_GeoIP_IpsetUnavailable') }}</p>
    </div>

    {# Data source selector and update button #}
    <div class="ui equal width fields">
        <div class="field">
            <label>{{ t._('mod_GeoIP_DataSource') }}</label>
            <div class="ui selection dropdown" id="geoip-data-source">
                <input type="hidden" name="dataSource" value="{{ record.dataSource }}">
                <i class="dropdown icon"></i>
                <div class="default text">{{ t._('mod_GeoIP_DataSource') }}</div>
                <div class="menu">
                    <div class="item" data-value="dbip">{{ t._('mod_GeoIP_DataSourceDBIP') }}</div>
                    <div class="item" data-value="rir">{{ t._('mod_GeoIP_DataSourceRIR') }}</div>
                    <div class="item" data-value="ipdeny">{{ t._('mod_GeoIP_DataSourceIpdeny') }}</div>
                </div>
            </div>
        </div>
        <div class="field">
            <label>&nbsp;</label>
            <button class="ui basic blue button" id="geoip-update-now" type="button"
                    data-tooltip="{{ t._('mod_GeoIP_NeverUpdated') }}" data-position="top left">
                <i class="sync icon"></i>
                {{ t._('mod_GeoIP_UpdateNow') }}
            </button>
        </div>
    </div>

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
