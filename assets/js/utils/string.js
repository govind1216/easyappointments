/* ----------------------------------------------------------------------------
 * Easy!Appointments - Open Source Web Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.0
 * ---------------------------------------------------------------------------- */

/**
 * Strings utility.
 *
 * This module implements the functionality of strings.
 */
window.App.Utils.String = (function () {
    /**
     * Upper case the first letter of the provided string.
     *
     * @param {String} value
     *
     * @returns {string}
     */
    function upperCaseFirstLetter(value) {
        return value.charAt(0).toUpperCase() + value.slice(1);
    }

    return {
        upperCaseFirstLetter
    };
})();
