import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  poweredByHeader: false,
  reactStrictMode: true,
  async redirects() {
    return [
      { source: "/index.php", destination: "/", permanent: true },
      { source: "/login.php", destination: "/login", permanent: true },
      { source: "/logout.php", destination: "/", permanent: false },
      {
        source: "/register_passenger.php",
        destination: "/register/passenger",
        permanent: true,
      },
      {
        source: "/register_driver.php",
        destination: "/register/driver",
        permanent: true,
      },
      { source: "/profile.php", destination: "/profile", permanent: true },
      {
        source: "/passenger/dashboard.php",
        destination: "/passenger/dashboard",
        permanent: true,
      },
      {
        source: "/passenger/request_ride.php",
        destination: "/passenger/request-ride",
        permanent: true,
      },
      {
        source: "/passenger/request_status.php",
        destination: "/passenger/rides",
        permanent: false,
      },
      {
        source: "/passenger/ride_detail.php",
        destination: "/passenger/rides",
        permanent: false,
      },
      {
        source: "/passenger/rides_history.php",
        destination: "/passenger/rides",
        permanent: true,
      },
      {
        source: "/passenger/payments.php",
        destination: "/passenger/payments",
        permanent: true,
      },
      {
        source: "/passenger/messages.php",
        destination: "/messages",
        permanent: true,
      },
      {
        source: "/passenger/settings.php",
        destination: "/passenger/settings",
        permanent: true,
      },
      {
        source: "/passenger/gdpr_request.php",
        destination: "/privacy",
        permanent: true,
      },
      {
        source: "/passenger/request_autonomous_ride.php",
        destination: "/autonomous",
        permanent: true,
      },
      {
        source: "/passenger/check_geofence_path.php",
        destination: "/passenger/request-ride",
        permanent: true,
      },
      {
        source: "/passenger/autonomous_ride_detail.php",
        destination: "/autonomous",
        permanent: false,
      },
      {
        source: "/carshare/request_vehicle.php",
        destination: "/carshare",
        permanent: true,
      },
      {
        source: "/carshare/register.php",
        destination: "/carshare",
        permanent: true,
      },
      {
        source: "/driver/dashboard.php",
        destination: "/driver/dashboard",
        permanent: true,
      },
      {
        source: "/driver/trips_assigned.php",
        destination: "/driver/trips",
        permanent: true,
      },
      {
        source: "/driver/trip_detail.php",
        destination: "/driver/trips",
        permanent: false,
      },
      {
        source: "/driver/ride_request_detail.php",
        destination: "/driver/trips",
        permanent: false,
      },
      {
        source: "/driver/segment_detail.php",
        destination: "/driver/trips",
        permanent: false,
      },
      {
        source: "/driver/accept_segment.php",
        destination: "/driver/trips",
        permanent: false,
      },
      {
        source: "/driver/vehicles.php",
        destination: "/driver/vehicles",
        permanent: true,
      },
      {
        source: "/driver/earnings.php",
        destination: "/driver/earnings",
        permanent: true,
      },
      {
        source: "/driver/messages.php",
        destination: "/messages",
        permanent: true,
      },
      {
        source: "/driver/settings.php",
        destination: "/driver/settings",
        permanent: true,
      },
      {
        source: "/driver/upload_documents.php",
        destination: "/driver/documents",
        permanent: true,
      },
      {
        source: "/operator/dashboard.php",
        destination: "/operator/dashboard",
        permanent: true,
      },
      {
        source: "/operator/drivers_hub.php",
        destination: "/operator/drivers",
        permanent: true,
      },
      {
        source: "/operator/drivers.php",
        destination: "/operator/drivers",
        permanent: true,
      },
      {
        source: "/operator/driver_details.php",
        destination: "/operator/drivers",
        permanent: false,
      },
      {
        source: "/operator/view_document.php",
        destination: "/operator/drivers",
        permanent: false,
      },
      {
        source: "/operator/messages.php",
        destination: "/messages",
        permanent: true,
      },
      {
        source: "/operator/reports.php",
        destination: "/operator/reports",
        permanent: true,
      },
      {
        source: "/operator/financial_reports.php",
        destination: "/operator/reports",
        permanent: true,
      },
      {
        source: "/operator/gdpr_requests.php",
        destination: "/operator/privacy",
        permanent: true,
      },
      {
        source: "/operator/autonomous_hub.php",
        destination: "/operator/autonomous",
        permanent: true,
      },
      {
        source: "/operator/carshare_hub.php",
        destination: "/operator/carshare",
        permanent: true,
      },
      {
        source: "/operator/carshare_approvals.php",
        destination: "/operator/carshare",
        permanent: true,
      },
      {
        source: "/operator/operations_hub.php",
        destination: "/operator/operations",
        permanent: true,
      },
      {
        source: "/operator/safety_inspections.php",
        destination: "/operator/safety",
        permanent: true,
      },
      {
        source: "/operator/system_logs.php",
        destination: "/operator/logs",
        permanent: true,
      },
      {
        source: "/operator/view_data.php",
        destination: "/operator/data",
        permanent: true,
      },
      {
        source: "/operator/driver_map.php",
        destination: "/operator/fleet-map",
        permanent: true,
      },
      {
        source: "/operator/autonomous_vehicle_map.php",
        destination: "/operator/fleet-map",
        permanent: true,
      },
      {
        source: "/operator/autonomous_vehicles.php",
        destination: "/operator/autonomous",
        permanent: true,
      },
      {
        source: "/operator/autonomous_vehicle_detail.php",
        destination: "/operator/autonomous",
        permanent: false,
      },
      {
        source: "/operator/autonomous_rides.php",
        destination: "/operator/autonomous",
        permanent: true,
      },
      {
        source: "/operator/autonomous_ride_detail.php",
        destination: "/operator/autonomous",
        permanent: false,
      },
      {
        source: "/operator/carshare_vehicles.php",
        destination: "/operator/carshare",
        permanent: true,
      },
      {
        source: "/operator/carshare_zones.php",
        destination: "/operator/carshare",
        permanent: true,
      },
    ];
  },
  async rewrites() {
    if (process.env.NODE_ENV !== "development") {
      return [];
    }
    return [
      {
        source: "/api/:path*",
        destination: `${process.env.LOCAL_API_ORIGIN ?? "http://127.0.0.1:8000"}/api/:path*`,
      },
    ];
  },
  experimental: {
    optimizePackageImports: ["lucide-react"],
  },
};

export default nextConfig;
