/**
 * Fingerprint = Kennung der nativen App-Teile (runtimeVersion für Updates „aus der Luft“).
 * Die Versionsnummer in app.json zählt nicht dazu: Ein reines Versions-Update soll
 * weiterhin als Update an vorhandene APKs gehen; nur echte native Änderungen
 * (neue Bausteine, Berechtigungen, SDK-Wechsel) erfordern eine neue APK.
 */
/** @type {import('expo/fingerprint').Config} */
const config = {
  sourceSkips: ['ExpoConfigVersions', 'PackageJsonAndroidAndIosScriptsIfNotContainRun'],
};
module.exports = config;
