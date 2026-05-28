const fs = require("fs");
const path = require("path");

const repoRoot = path.resolve(__dirname, "..");
const liveAvailabilityUrl = "https://freshcoastgarage.com/api/availability.php";
const localAvailabilityPath = path.join(repoRoot, "api", "data", "vehicle-availability.json");

function slugifyVehicleFolder(value) {
  const slug = String(value || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

  return slug || "vehicle";
}

function getVehicleFolder(vehicle, index) {
  if (vehicle.imageFolder) return vehicle.imageFolder;

  const name =
    vehicle.nickname ||
    vehicle.displayName ||
    vehicle.name ||
    vehicle.vehicleId ||
    `vehicle-${index + 1}`;

  return `images/${slugifyVehicleFolder(name)}`;
}

async function loadAvailability() {
  if (process.argv.includes("--local")) {
    return JSON.parse(fs.readFileSync(localAvailabilityPath, "utf8"));
  }

  const response = await fetch(liveAvailabilityUrl);
  if (!response.ok) {
    throw new Error(`Failed to fetch live availability: ${response.status}`);
  }

  return response.json();
}

function ensureVehicleFolder(imageFolder) {
  const relativeFolder = String(imageFolder || "").replace(/^\/+/, "");
  if (!relativeFolder.startsWith("images/")) {
    throw new Error(`Refusing to create folder outside images/: ${imageFolder}`);
  }

  const folderPath = path.join(repoRoot, relativeFolder);
  const resolvedFolderPath = path.resolve(folderPath);
  const imagesRoot = path.resolve(repoRoot, "images");

  if (!resolvedFolderPath.startsWith(imagesRoot + path.sep)) {
    throw new Error(`Refusing unsafe folder path: ${imageFolder}`);
  }

  fs.mkdirSync(resolvedFolderPath, { recursive: true });
  fs.closeSync(fs.openSync(path.join(resolvedFolderPath, ".gitkeep"), "a"));

  return path.relative(repoRoot, resolvedFolderPath);
}

async function main() {
  const availability = await loadAvailability();
  const vehicles = availability.payload?.vehicles || availability.vehicles || [];

  const createdFolders = vehicles.map((vehicle, index) => {
    return ensureVehicleFolder(getVehicleFolder(vehicle, index));
  });

  [...new Set(createdFolders)].forEach(folder => {
    console.log(folder);
  });
}

main().catch(error => {
  console.error(error.message);
  process.exit(1);
});
