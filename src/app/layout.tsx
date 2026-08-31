import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "leaflet/dist/leaflet.css";
import "../../OSRH_KASPA_PHP/assets/css/main.css";
import "../../OSRH_KASPA_PHP/assets/css/layout.css";
import "../../OSRH_KASPA_PHP/assets/css/components.css";
import "../../OSRH_KASPA_PHP/assets/css/maps.css";
import "../../OSRH_KASPA_PHP/assets/css/responsive.css";
import "./legacy-inline.css";
import "./globals.css";
import { AppShell } from "@/components/app-shell";
import { OsrhProvider } from "@/components/osrh-provider";

const body = Inter({
  subsets: ["latin"],
  variable: "--font-osrh",
  weight: ["400", "500", "600", "700", "800"],
  display: "swap",
});

export const metadata: Metadata = {
  title: "OSRH — Kaspa Ridehailing",
  description:
    "Kaspa-powered ride-hailing for passengers, drivers, and mobility operators in Cyprus.",
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en" className={body.variable}>
      <body className="theme-dark">
        <OsrhProvider>
          <AppShell>{children}</AppShell>
        </OsrhProvider>
      </body>
    </html>
  );
}
