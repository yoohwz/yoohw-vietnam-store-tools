=== Vietnam Store Toolkit for WooCommerce ===
Contributors: yoohw, baonguyen0310
Tags: woocommerce, vietnam, vietqr, checkout blocks, shipping
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.9
WC tested up to: 10.9
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bộ công cụ WooCommerce Việt Nam cho địa chỉ hai cấp, Checkout Blocks, VietQR, hóa đơn GTGT, phí vận chuyển và tracking đơn hàng.

== Description ==

Vietnam Store Toolkit for WooCommerce bổ sung các công cụ cần thiết cho cửa hàng tại Việt Nam: địa chỉ tỉnh/thành phố và phường/xã, VietQR, hóa đơn GTGT, số điện thoại, phí vận chuyển, mã vận đơn và tra cứu đơn hàng.

= Liên kết chính thức =

* [Website Vietnam Store Toolkit](https://vietnamstore.org/) — giới thiệu tổng quan và các tính năng.
* [Tài liệu sử dụng](https://vietnamstore.org/documentation/) — yêu cầu hệ thống, cấu hình và ví dụ.
* [Hỗ trợ](https://vietnamstore.org/support/) — nguồn hỗ trợ chính thức và cách gửi yêu cầu.
* [Mã nguồn và phát triển trên GitHub](https://github.com/yoohwz/yoohw-vietnam-store-tools) — xem code, báo lỗi, đề xuất tính năng và pull request.
* [Bản phát hành trên WordPress.org](https://wordpress.org/plugins/yoohw-vietnam-store-tools/) — nguồn cài đặt công khai chính thức.

= Tính năng chính =

* Địa chỉ hai cấp gồm 34 tỉnh/thành phố và 3.321 phường/xã/đặc khu.
* Danh sách Phường/Xã phụ thuộc Tỉnh/Thành trong Classic Checkout, Cart và Checkout Blocks.
* Yêu cầu hóa đơn GTGT và quy trình hóa đơn điện tử trung lập nhà cung cấp.
* VietQR cho WooCommerce Direct bank transfer.
* Chuẩn hóa và xác thực số điện thoại Việt Nam.
* Quy tắc phí vận chuyển theo địa chỉ, giỏ hàng, khối lượng, shipping class, miễn phí vận chuyển và COD.
* Mã vận đơn, timeline thủ công và trang tra cứu đơn hàng không cần API hãng vận chuyển.
* Bộ lọc, thao tác hàng loạt và xuất CSV đơn hàng, tương thích HPOS.
* Công cụ di chuyển địa chỉ và dữ liệu GHTK từ plugin của Le Van Toan.

= Có gì mới trong phiên bản 1.1.1 =

Phiên bản 1.1.1 bổ sung trang quản trị Vietnam store, tùy chọn bật/tắt tính năng, cải thiện quy trình hóa đơn và đảm bảo email theo dõi luôn dùng vận đơn hiện hành mới nhất.

= Địa chỉ Việt Nam và Checkout Blocks =

Plugin lưu mã tỉnh/thành phố vào `state` và mã phường/xã vào `city` của WooCommerce, đồng thời ẩn mã bưu chính khi phù hợp. Tổ hợp địa chỉ được xác thực trước khi lưu và danh sách phường/xã chỉ tải khi cần.

Các trường được áp dụng cho checkout, My Account, giỏ hàng, địa chỉ cửa hàng, hồ sơ khách hàng và đơn hàng trong wp-admin. Trong Cart và Checkout Blocks, Phường/Xã là danh sách phụ thuộc Tỉnh/Thành và đồng bộ trực tiếp với Store API, kể cả trên trang block tùy chỉnh.

= Hóa đơn GTGT và VietQR =

Tính năng nhận yêu cầu hóa đơn được bật độc lập trong Vietnam store > Tính năng cốt lõi và không phụ thuộc vào việc tính thuế của WooCommerce. Classic Checkout và Checkout Block có thể thu thập tên công ty, mã số thuế, email nhận hóa đơn và địa chỉ công ty.

Quy trình hóa đơn điện tử lưu trạng thái, số/ký hiệu, ngày phát hành, URL tra cứu, nhà cung cấp, tệp PDF/XML và nhật ký thay đổi. Plugin không tự phát hành hóa đơn hoặc gọi API nhà cung cấp.

VietQR được thêm vào WooCommerce Direct bank transfer (`bacs`), không tạo cổng thanh toán mới. Ảnh QR và thông tin chuyển khoản có thể xuất hiện trên trang xác nhận đơn hàng, My Account, email và wp-admin. Plugin không xác nhận giao dịch hoặc tự đánh dấu đơn đã thanh toán.

= Phí vận chuyển và tracking đơn hàng =

Phương thức “Quy tắc phí vận chuyển” hoạt động trong WooCommerce Shipping Zones. Quy tắc được kiểm tra từ trên xuống dưới và có thể dựa trên tỉnh/thành phố, phường/xã, tổng giỏ hàng, khối lượng, shipping class, phí, ngưỡng miễn phí và COD. Trình chỉnh sửa hỗ trợ sắp xếp cùng nhập/xuất CSV UTF-8.

Hộp Vận chuyển cho phép nhập hãng, mã vận đơn, URL theo dõi, gửi email và ghi timeline thủ công. Mẫu URL tự tạo liên kết từ `{tracking_code}`; quản trị viên cũng có thể khai báo hãng tùy chỉnh.

Thêm block “Tra cứu đơn hàng” hoặc shortcode `[yoohw_order_tracking]` để khách tra cứu bằng mã đơn cùng email hoặc số điện thoại thanh toán. Kết quả không hiển thị địa chỉ, sản phẩm, tổng tiền hay thông tin liên hệ.

= Quản lý đơn hàng và HPOS =

Màn hình WooCommerce > Đơn hàng có cột Thông tin gọn cho hóa đơn và vận đơn, bộ lọc nâng cao, thao tác gửi lại email tracking, cập nhật hãng, xuất CSV và đánh dấu tiến độ bàn giao. Plugin dùng API đơn hàng WooCommerce, tương thích HPOS và chế độ lưu trữ cũ.

= Di chuyển và dữ liệu =

WooCommerce Status Tools có thể quét, sao lưu và đồng bộ theo lô địa chỉ cũ cùng dữ liệu GHTK từ plugin của Le Van Toan. Hãy chạy công cụ quét và xem báo cáo trước khi đồng bộ.

Dữ liệu hành chính phiên bản 2026-07 dựa trên National Statistics Office of Viet Nam thông qua Vietnam Provinces API v2. Danh sách ngân hàng và BIN dựa trên VietQR bank list API. Nguồn và quy trình cập nhật được ghi trong `data/SOURCES.md`.

= Dịch vụ bên ngoài và quyền riêng tư =

Các API nguồn chỉ được dùng để xây dựng dữ liệu tĩnh; plugin không gọi chúng trong thời gian chạy. Khi VietQR được bật, trình duyệt hoặc trình đọc email tải ảnh QR từ VietQR.io by CASSO. URL ảnh có thể chứa BIN, số tài khoản, mẫu QR, số tiền, nội dung chuyển khoản và tên chủ tài khoản.

* Dịch vụ: https://vietqr.io/
* Tài liệu: https://vietqr.io/danh-sach-api/link-tao-ma-nhan/
* Điều khoản: https://casso.vn/thoa-thuan-su-dung-phan-mem/
* Quyền riêng tư: https://casso.vn/chinh-sach-bao-mat-thong-tin/

Dữ liệu địa chỉ, điện thoại, hóa đơn và vận chuyển được lưu trong WordPress/WooCommerce của cửa hàng. Plugin không thêm phân tích, quảng cáo hoặc dịch vụ thu thập dữ liệu từ xa.

== Installation ==

1. Cài đặt và kích hoạt WooCommerce.
2. Cài và kích hoạt Vietnam Store Toolkit for WooCommerce.
3. Kiểm tra khu vực bán hàng và địa chỉ cửa hàng trong cài đặt WooCommerce.
4. Cấu hình Direct bank transfer nếu cần VietQR.
5. Nếu cần hóa đơn, bật “Nhận yêu cầu hóa đơn tại trang thanh toán” trong Vietnam store > Tính năng cốt lõi.
6. Nếu cần phí theo địa chỉ, thêm “Quy tắc phí vận chuyển” vào Shipping Zone.
7. Nếu chuyển từ plugin của Le Van Toan, chạy công cụ quét trước khi đồng bộ.

== Frequently Asked Questions ==

= Plugin có hỗ trợ Cart và Checkout Blocks không? =

Có. Plugin hỗ trợ Tỉnh/Thành, Phường/Xã, hóa đơn GTGT, xác thực số điện thoại, VietQR và thông tin vận chuyển. WooCommerce 8.9 trở lên được yêu cầu.

= Plugin lưu địa chỉ Việt Nam như thế nào? =

Mã tỉnh/thành phố được lưu vào `state`; mã phường/xã/đặc khu được lưu vào `city` của WooCommerce.

= Khách hàng có thể yêu cầu hóa đơn GTGT không? =

Có, khi “Nhận yêu cầu hóa đơn tại trang thanh toán” được bật trong Vietnam store > Tính năng cốt lõi.

= VietQR có tự xác nhận thanh toán không? =

Không. VietQR được thêm vào Direct bank transfer nhưng không kết nối giao dịch ngân hàng.

= Tôi cấu hình phí vận chuyển đến cấp phường/xã ở đâu? =

Đi tới WooCommerce > Cài đặt > Vận chuyển, mở một khu vực và thêm “Quy tắc phí vận chuyển”.

= Tôi tạo trang tra cứu đơn hàng như thế nào? =

Thêm block “Tra cứu đơn hàng” hoặc shortcode `[yoohw_order_tracking]` vào một trang.

= Plugin có tích hợp sẵn API hãng vận chuyển không? =

Không. Bản công khai cung cấp tracking không cần API và khung cho trình kết nối riêng.

= Plugin có hỗ trợ HPOS và tiếng Việt không? =

Có. Plugin tương thích HPOS và bao gồm bản dịch tiếng Việt cho giao diện, email cùng thông báo xác thực.

== Changelog ==

= 1.1.1 (26/7/2026) =

* Bổ sung menu và trang quản trị Vietnam store ngay dưới WooCommerce, quy tụ liên kết cấu hình cùng các switch theo color scheme của từng người dùng.
* Thiết kế hero với icon plugin và icon SVG riêng cho menu quản trị, đồng thời loại bỏ hiện tượng nhấp nháy khi chuyển trang.
* Cung cấp tùy chọn bật/tắt trường địa chỉ, chuẩn hóa điện thoại, thông tin vận chuyển cho khách hàng, quản lý đơn hàng, nhận yêu cầu hóa đơn tại checkout và quy trình hóa đơn điện tử.
* Tách yêu cầu hóa đơn khỏi WooCommerce Taxes; dữ liệu lịch sử vẫn có thể xem, lọc và xuất khi cửa hàng ngừng nhận yêu cầu mới.
* Chuyển trạng thái, tài liệu và lịch sử hóa đơn điện tử sang chế độ chỉ đọc khi quy trình quản lý bị tắt.
* Sửa email Theo dõi vận chuyển để luôn sử dụng vận đơn hiện hành mới nhất sau khi vận đơn cũ bị hủy và tạo lại.
* Rút gọn mã vận đơn GHTK trên giao diện và clipboard còn dãy số cuối sau dấu chấm, trong khi vẫn giữ mã đầy đủ cho tích hợp.
* Tự động ẩn Hành trình vận chuyển thủ công khi có ít nhất một dịch vụ vận chuyển khả dụng.

Xem lịch sử thay đổi chi tiết trong `changelog.txt`.
